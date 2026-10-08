<?php

declare(strict_types=1);

namespace Loongs\Saas\Resolver;

use Loongs\Orm\Connection\ConnectionConfig;
use Loongs\Saas\Tenant;
use Loongs\Saas\TenantResolver;

/** Fixed map: id => Tenant | connection (name, config array, DSN, ConnectionConfig). */
final readonly class ArrayTenantResolver implements TenantResolver
{
    /** @param array<string, Tenant|string|array<string, mixed>|ConnectionConfig> $tenants */
    public function __construct(private array $tenants)
    {
    }

    public function resolve(string $id): ?Tenant
    {
        $t = $this->tenants[$id] ?? null;
        if ($t === null) {
            return null;
        }

        return $t instanceof Tenant ? $t : Tenant::make($id, $t);
    }
}
