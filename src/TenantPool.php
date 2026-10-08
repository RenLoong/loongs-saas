<?php

declare(strict_types=1);

namespace Loongs\Saas;

use Closure;
use Loongs\Orm\Connection\ConnectionConfig;
use Loongs\Orm\Connection\ConnectionPool;
use Loongs\Orm\Connection\Lease;
use Loongs\Orm\Connection\LeaseProvider;
use Loongs\Orm\Connection\LeaseSource;
use Loongs\Orm\Connection\PdoFactory;
use Loongs\Orm\Orm;

/**
 * Per-worker tenant connection pools: every registered tenant gets its own bounded pool (a bucket
 * of loongs/orm's ConnectionPool keyed by the tenant's full config fingerprint, credentials
 * included), separate from the framework PDOPool and from the ORM's own pool.
 *
 * Plugged into loongs/orm as a LeaseProvider (Tenancy::install()): every statement, transaction or
 * Orm::acquire() whose connection config belongs to a registered tenant — via Tenancy::run(),
 * Model::on(Tenancy::config($id)), a model loaded from that tenant, or the same config array — is
 * served from that tenant's pool; anything else is declined and goes on to the framework / ORM pool.
 *
 * Same lifecycle guarantees as every loongs/orm pool: connections go back on release (manual
 * release(), end of statement / transaction / using()), are returned (rolled back) on exceptions and
 * at coroutine end, broken ones are discarded and replaced; idle / ttl eviction; DATABASE()
 * validation on checkout; at most `max_tenants` tenants keep connections open (LRU among idle ones).
 *
 * Registrations are process-wide configuration (tenant id → connection config), not request
 * state. Tenants whose configs are identical share one pool.
 */
final class TenantPool implements LeaseProvider
{
    private ConnectionPool $pool;

    /** @var array<string, array<string, true>> config fingerprint => tenant ids using it */
    private array $routes = [];

    /** @var array<string, ConnectionConfig> tenant id => config */
    private array $configs = [];

    /** @param (Closure(ConnectionConfig): object)|null $connector default PdoFactory::make */
    public function __construct(public readonly TenantPoolConfig $config = new TenantPoolConfig(), ?Closure $connector = null)
    {
        $this->pool = new ConnectionPool(
            $config->toPoolConfig(),
            $connector ?? static fn (ConnectionConfig $c): object => PdoFactory::make($c),
            static fn (object $pdo): int => Orm::resolver()->serial($pdo),
            LeaseSource::Provider,
        );
    }

    /**
     * Register (or refresh) a tenant and return the connection config to use for it. A tenant whose
     * connection changed (credential rotation, moved database) drops its old pool: idle connections
     * close now, checked-out ones when they come back.
     */
    public function register(Tenant $tenant): ConnectionConfig
    {
        return $this->registerConfig($tenant->id, $tenant->connectionConfig());
    }

    public function registerConfig(string $id, ConnectionConfig $config): ConnectionConfig
    {
        $old = $this->configs[$id] ?? null;
        if ($old !== null && $old->fingerprint() !== $config->fingerprint()) {
            $this->unroute($id, $old);
        }
        $this->configs[$id] = $config;
        $this->routes[$config->fingerprint()][$id] = true;

        return $config;
    }

    /** LeaseProvider: serve registered tenant configs, decline everything else. */
    public function lease(ConnectionConfig $c): ?Lease
    {
        return isset($this->routes[$c->fingerprint()]) ? $this->pool->acquire($c) : null;
    }

    public function owns(ConnectionConfig $c): bool
    {
        return isset($this->routes[$c->fingerprint()]);
    }

    /** The (first registered) tenant id using $c, or null. */
    public function tenantOf(ConnectionConfig $c): ?string
    {
        $ids = $this->routes[$c->fingerprint()] ?? [];

        return $ids === [] ? null : (string) array_key_first($ids);
    }

    public function configOf(string $id): ?ConnectionConfig
    {
        return $this->configs[$id] ?? null;
    }

    /** @return list<string> registered tenant ids */
    public function tenants(): array
    {
        return array_map('strval', array_keys($this->configs));
    }

    /**
     * Totals, or open/active/idle/waiting for one tenant.
     *
     * @return array<string, int>
     */
    public function stats(?string $tenant = null): array
    {
        if ($tenant !== null) {
            $c = $this->configs[$tenant] ?? null;

            return $c !== null ? $this->pool->stats($c) : ['open' => 0, 'active' => 0, 'idle' => 0, 'waiting' => 0];
        }
        $s = $this->pool->stats();

        return ['tenants' => $s['pools'], 'registered' => count($this->configs), 'open' => $s['open'], 'active' => $s['active'], 'idle' => $s['idle'],
            'waiting' => $s['waiting'], 'created' => $s['created'], 'closed' => $s['closed'], 'hits' => $s['hits'], 'discarded' => $s['discarded'],
            'waits' => $s['waits'], 'timeouts' => $s['timeouts'], 'evicted_tenants' => $s['evicted_pools']];
    }

    /** Unregister a tenant (offboarding): its pool is retired and its config is no longer routed here. */
    public function forget(string $tenant): bool
    {
        $c = $this->configs[$tenant] ?? null;
        if ($c === null) {
            return false;
        }
        unset($this->configs[$tenant]);
        $this->unroute($tenant, $c);

        return true;
    }

    /** Close every idle connection of every tenant (registrations are kept). */
    public function flush(): void
    {
        $this->pool->flush();
    }

    /** Close expired idle connections now (otherwise done lazily on checkout, at most once per second). */
    public function sweep(): void
    {
        $this->pool->sweep();
    }

    /** Move the registrations of $other into this pool (Tenancy::configurePool()). */
    public function adopt(self $other): void
    {
        foreach ($other->configs as $id => $c) {
            $this->registerConfig((string) $id, $c);
        }
    }

    /** The underlying loongs/orm pool (diagnostics). */
    public function connectionPool(): ConnectionPool
    {
        return $this->pool;
    }

    /** Drop $id from its config's route; the pool is retired when no other tenant uses that identical config. */
    private function unroute(string $id, ConnectionConfig $c): void
    {
        $fp = $c->fingerprint();
        unset($this->routes[$fp][$id]);
        if (($this->routes[$fp] ?? []) === []) {
            unset($this->routes[$fp]);
            $this->pool->forget($c);
        }
    }
}
