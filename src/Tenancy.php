<?php

declare(strict_types=1);

namespace Loongs\Saas;

use Closure;
use Loongs\Orm\Connection\Connection;
use Loongs\Orm\Connection\ConnectionConfig;
use Loongs\Orm\Connection\ConnectionResolver;
use Loongs\Orm\Context;
use Loongs\Orm\Orm;
use Loongs\Orm\Query\Builder;
use Loongs\Saas\Exceptions\NoTenantException;
use Loongs\Saas\Exceptions\TenantNotFoundException;
use Loongs\Saas\Resolver\ArrayTenantResolver;
use Loongs\Saas\Resolver\CallbackTenantResolver;
use Swoole\Coroutine;

/**
 * Multi-tenancy entry point.
 *
 *   Tenancy::resolveUsing(fn (string $id) => …);              // id → Tenant / connection spec
 *   Tenancy::run('acme', fn () => User::where(…)->get());      // coroutine-local tenant scope
 *   User::on(Tenancy::config('acme'))->find(1);                // explicit, per call
 *
 * - Scope: inside Tenancy::run() every ORM call without an explicit connection (models without a
 *   bound / declared connection, Orm::table(), Orm::connection()) goes to the tenant. The scope lives
 *   in the coroutine context: private to this coroutine, restored on exit (also on exceptions),
 *   nestable, not inherited by child coroutines — use Tenancy::go().
 * - Pools: each tenant has its own bounded pool (TenantPool). Calls without a tenant are untouched
 *   and keep using the framework PDOPool (named connections) or the ORM pool.
 * - Models keep only a connection config: a model loaded from tenant A still saves to A inside a
 *   scope of tenant B. Declared connections ($connection = 'central') ignore the scope.
 *
 * Process-wide state here is configuration only (resolver, pool settings, the pool itself).
 * Hooks into loongs/orm through its public extension points (Orm::resolveDefaultUsing(),
 * Orm::addLeaseProvider()); installed lazily on first use, or explicitly with install().
 */
final class Tenancy
{
    public const string CONTEXT_KEY = 'loongs.saas.tenant';

    private static ?TenantResolver $resolver = null;

    private static ?TenantPool $pool = null;

    private static ?TenantPoolConfig $poolConfig = null;

    private static ?ConnectionResolver $installedOn = null;

    /** @var (Closure(): mixed)|null default-connection hook that was set before ours (chained) */
    private static ?Closure $previousDefault = null;

    private static ?Closure $hook = null;

    // ------------------------------------------------------------------ wiring

    /** Hook into the current Orm resolver (idempotent; re-installs after Orm::setResolver()). */
    public static function install(): void
    {
        $r = Orm::resolver();
        if (self::$installedOn === $r) {
            return;
        }
        self::$hook ??= static function (): mixed {
            $scope = self::scope();
            if ($scope !== null) {
                return $scope->config;
            }
            $prev = self::$previousDefault;

            return $prev !== null ? $prev() : null;
        };
        $current = $r->defaultResolver();
        self::$previousDefault = $current !== self::$hook ? $current : null;
        $r->setDefaultResolver(self::$hook);
        $r->addLeaseProvider(self::pool());
        self::$installedOn = $r;
    }

    /** Remove the hooks from the Orm resolver (the previous default-connection hook is restored). */
    public static function uninstall(): void
    {
        $r = self::$installedOn;
        if ($r === null) {
            return;
        }
        if ($r->defaultResolver() === self::$hook) {
            $r->setDefaultResolver(self::$previousDefault);
        }
        if (self::$pool !== null) {
            $r->removeLeaseProvider(self::$pool);
        }
        self::$installedOn = null;
        self::$previousDefault = null;
    }

    public static function installed(): bool
    {
        return self::$installedOn !== null && self::$installedOn === Orm::resolver();
    }

    /** Forget everything (tests): uninstall, close idle tenant connections, drop resolver / pool / settings. */
    public static function reset(): void
    {
        self::uninstall();
        self::$pool?->flush();
        self::$pool = null;
        self::$poolConfig = null;
        self::$resolver = null;
    }

    // ------------------------------------------------------------------ tenants

    /**
     * How tenant ids are looked up: a TenantResolver, a Closure(string $id): Tenant|spec|null, or an
     * array id => Tenant|spec. null removes it (then only connection specs / connection names work).
     *
     * @param TenantResolver|Closure(string): (Tenant|string|array<string, mixed>|ConnectionConfig|null)|array<string, Tenant|string|array<string, mixed>|ConnectionConfig>|null $resolver
     */
    public static function resolveUsing(TenantResolver|Closure|array|null $resolver): void
    {
        self::$resolver = match (true) {
            $resolver instanceof Closure => new CallbackTenantResolver($resolver),
            is_array($resolver) => new ArrayTenantResolver($resolver),
            default => $resolver,
        };
    }

    public static function resolver(): ?TenantResolver
    {
        return self::$resolver;
    }

    /** Look a tenant id up (resolver only). */
    public static function find(string $id): ?Tenant
    {
        return self::$resolver?->resolve($id);
    }

    /**
     * Normalise to a Tenant: a Tenant; a tenant id (resolver; else a configured connection of that
     * name, id = name); a URL / PDO DSN, config array or ConnectionConfig (id = database name).
     *
     * @param Tenant|string|array<string, mixed>|ConnectionConfig $tenant
     * @throws TenantNotFoundException
     */
    public static function tenant(Tenant|string|array|ConnectionConfig $tenant): Tenant
    {
        if ($tenant instanceof Tenant) {
            return $tenant;
        }
        if (!is_string($tenant)) {
            return Tenant::fromConnection($tenant);
        }
        if (str_contains($tenant, '://') || preg_match('/^[a-z0-9_]+:[a-z_]+=/i', $tenant) === 1) {
            return Tenant::fromConnection($tenant);
        }
        $found = self::find($tenant);
        if ($found !== null) {
            return $found;
        }
        $connections = Orm::resolver()->databaseConfig()['connections'] ?? [];
        if (is_array($connections) && isset($connections[$tenant]) && is_array($connections[$tenant])) {
            return Tenant::make($tenant, $tenant);
        }

        throw TenantNotFoundException::forId($tenant);
    }

    /**
     * The (registered) connection config of a tenant — pass it to Model::on(), Orm::table(), … to
     * work on that tenant explicitly. null = the current scope's tenant.
     *
     * @param Tenant|string|array<string, mixed>|ConnectionConfig|null $tenant
     * @throws NoTenantException when $tenant is null outside a tenant scope
     */
    public static function config(Tenant|string|array|ConnectionConfig|null $tenant = null): ConnectionConfig
    {
        self::install();
        if ($tenant === null) {
            return self::scope()?->config ?? throw new NoTenantException('No tenant scope is active (use Tenancy::run() or pass a tenant).');
        }

        return self::pool()->register(self::tenant($tenant));
    }

    // ------------------------------------------------------------------ scopes

    /**
     * Run $callback inside a tenant scope (coroutine-local, nestable, restored on exit / exception).
     *
     * @template T
     * @param Tenant|string|array<string, mixed>|ConnectionConfig $tenant
     * @param callable(Tenant): T $callback
     * @return T
     */
    public static function run(Tenant|string|array|ConnectionConfig $tenant, callable $callback): mixed
    {
        self::install();
        $t = self::tenant($tenant);
        $scope = new TenantScope($t, self::pool()->register($t));

        return Context::with(self::CONTEXT_KEY, $scope, static fn () => $callback($t));
    }

    /**
     * Run $callback with NO tenant scope (central database / default connection), e.g. from inside
     * a tenant request.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function central(callable $callback): mixed
    {
        return Context::with(self::CONTEXT_KEY, null, $callback);
    }

    /** Coroutine::create() that carries the current tenant scope into the new coroutine. */
    public static function go(callable $callback): int|false
    {
        $scope = self::scope();

        return Coroutine::create(static fn () => $scope !== null ? Context::with(self::CONTEXT_KEY, $scope, $callback) : $callback());
    }

    public static function current(): ?Tenant
    {
        return self::scope()?->tenant;
    }

    public static function id(): ?string
    {
        return self::scope()?->tenant->id;
    }

    /** True inside a tenant scope. */
    public static function active(): bool
    {
        return self::scope() !== null;
    }

    /** The current scope's connection config, or null (replaces Orm::currentTenant()). */
    public static function connectionConfig(): ?ConnectionConfig
    {
        return self::scope()?->config;
    }

    // ------------------------------------------------------------------ connections

    /**
     * Connection handle for a tenant (null = current scope). A new handle every call; holds no PDO.
     *
     * @param Tenant|string|array<string, mixed>|ConnectionConfig|null $tenant
     */
    public static function connection(Tenant|string|array|ConnectionConfig|null $tenant = null): Connection
    {
        return Orm::connection(self::config($tenant));
    }

    /** @param Tenant|string|array<string, mixed>|ConnectionConfig|null $tenant */
    public static function table(string $table, Tenant|string|array|ConnectionConfig|null $tenant = null): Builder
    {
        return Orm::table($table, self::config($tenant));
    }

    /**
     * Check a tenant connection out for manual control (Orm::acquire semantics: until release(),
     * every statement on this tenant in the current coroutine uses it; use try/finally or using()).
     *
     * @param Tenant|string|array<string, mixed>|ConnectionConfig|null $tenant
     */
    public static function acquire(Tenant|string|array|ConnectionConfig|null $tenant = null): Connection
    {
        return Orm::acquire(self::config($tenant));
    }

    /**
     * acquire() + $callback + release whatever happens (rollback of an open transaction, broken
     * connections discarded).
     *
     * @template T
     * @param Tenant|string|array<string, mixed>|ConnectionConfig|null $tenant
     * @param callable(Connection): T $callback
     * @return T
     */
    public static function using(Tenant|string|array|ConnectionConfig|null $tenant, callable $callback): mixed
    {
        return Orm::using(self::config($tenant), $callback);
    }

    /**
     * @template T
     * @param callable(Connection): T $callback
     * @param Tenant|string|array<string, mixed>|ConnectionConfig|null $tenant
     * @return T
     */
    public static function transaction(callable $callback, Tenant|string|array|ConnectionConfig|null $tenant = null, int $attempts = 1): mixed
    {
        return Orm::transaction($callback, self::config($tenant), $attempts);
    }

    // ------------------------------------------------------------------ pool

    /**
     * Tenant pool settings: TenantPoolConfig or ['size' => 8, 'max_tenants' => 64, 'idle_seconds' => 60,
     * 'ttl' => 600, 'wait_timeout' => 3, 'validate' => true]. Without it: config('database.tenant_pool')
     * → TENANT_POOL_* env → defaults. Replacing the settings closes the old pool's idle connections;
     * registered tenants are kept.
     *
     * @param TenantPoolConfig|array<string, mixed> $config
     */
    public static function configurePool(TenantPoolConfig|array $config): TenantPool
    {
        self::$poolConfig = is_array($config) ? TenantPoolConfig::fromArray($config) : $config;
        $old = self::$pool;
        if ($old !== null) {
            self::$installedOn?->removeLeaseProvider($old);
            $old->flush();
            self::$pool = null;
            self::pool()->adopt($old);
            self::$installedOn?->addLeaseProvider(self::pool());
        }

        return self::pool();
    }

    /** The tenant pool of this worker process. */
    public static function pool(): TenantPool
    {
        return self::$pool ??= new TenantPool(self::resolvePoolConfig());
    }

    public static function resolvePoolConfig(): TenantPoolConfig
    {
        if (self::$poolConfig !== null) {
            return self::$poolConfig;
        }
        $fromConfig = Orm::resolver()->databaseConfig()['tenant_pool'] ?? [];

        return TenantPoolConfig::fromArray(array_replace(TenantPoolConfig::envOverrides(), is_array($fromConfig) ? $fromConfig : []));
    }

    /**
     * Tenant pool counters: totals (tenants, registered, open, active, idle, waiting, created, closed,
     * hits, discarded, waits, timeouts, evicted_tenants) or open/active/idle/waiting for one tenant.
     *
     * @param Tenant|string|null $tenant tenant or tenant id
     * @return array<string, int>
     */
    public static function poolStats(Tenant|string|null $tenant = null): array
    {
        return self::pool()->stats($tenant instanceof Tenant ? $tenant->id : $tenant);
    }

    /** Offboard a tenant from this worker: its pool is retired, its config no longer routed. */
    public static function forget(Tenant|string $tenant): bool
    {
        return self::pool()->forget($tenant instanceof Tenant ? $tenant->id : $tenant);
    }

    private static function scope(): ?TenantScope
    {
        $s = Context::get(self::CONTEXT_KEY);

        return $s instanceof TenantScope ? $s : null;
    }
}
