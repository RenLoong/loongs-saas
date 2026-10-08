<?php

declare(strict_types=1);

namespace Loongs\Saas;

use InvalidArgumentException;
use Loongs\Orm\Connection\PoolConfig;

/**
 * Settings of the tenant pool. Resolution: Tenancy::configurePool() → config('database.tenant_pool')
 * → TENANT_POOL_* env → defaults.
 *
 *   size          max open connections per tenant (checked out + idle)                     default 8
 *   max_tenants   max tenants with open connections; least recently used idle tenant evicted  default 64
 *   idle_seconds  idle connections older than this are closed                              default 60
 *   ttl           connections older than this are closed when returned / found idle        default 600 (0 = no limit)
 *   wait_timeout  seconds to wait for a free connection when a tenant is at size           default 3 (-1 = forever)
 *   validate      check the connection (not in a transaction, SELECT DATABASE()) on checkout  default true
 */
final readonly class TenantPoolConfig
{
    public const array KEYS = ['size', 'max_tenants', 'idle_seconds', 'ttl', 'wait_timeout', 'validate'];

    public function __construct(
        public int $size = 8,
        public int $maxTenants = 64,
        public float $idleSeconds = 60.0,
        public float $ttl = 600.0,
        public float $waitTimeout = 3.0,
        public bool $validate = true,
    ) {
        if ($size < 1 || $maxTenants < 1) {
            throw new InvalidArgumentException('Tenant pool size and max_tenants must be >= 1.');
        }
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $d = new self();

        return new self(
            size: (int) ($a['size'] ?? $d->size),
            maxTenants: (int) ($a['max_tenants'] ?? $d->maxTenants),
            idleSeconds: (float) ($a['idle_seconds'] ?? $d->idleSeconds),
            ttl: (float) ($a['ttl'] ?? $d->ttl),
            waitTimeout: (float) ($a['wait_timeout'] ?? $d->waitTimeout),
            validate: filter_var($a['validate'] ?? $d->validate, FILTER_VALIDATE_BOOL),
        );
    }

    /** TENANT_POOL_SIZE, TENANT_POOL_MAX_TENANTS, TENANT_POOL_IDLE_SECONDS, TENANT_POOL_TTL, TENANT_POOL_WAIT_TIMEOUT, TENANT_POOL_VALIDATE. @return array<string, string> */
    public static function envOverrides(): array
    {
        return PoolConfig::envOverrides('TENANT_POOL_', self::KEYS);
    }

    /** The generic loongs/orm pool settings this maps to (one bucket per tenant, max_pools = max_tenants). */
    public function toPoolConfig(): PoolConfig
    {
        return new PoolConfig($this->size, $this->maxTenants, $this->idleSeconds, $this->ttl, $this->waitTimeout, $this->validate);
    }

    /** @return array<string, int|float|bool> */
    public function toArray(): array
    {
        return ['size' => $this->size, 'max_tenants' => $this->maxTenants, 'idle_seconds' => $this->idleSeconds, 'ttl' => $this->ttl, 'wait_timeout' => $this->waitTimeout, 'validate' => $this->validate];
    }
}
