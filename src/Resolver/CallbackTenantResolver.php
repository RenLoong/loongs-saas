<?php

declare(strict_types=1);

namespace Loongs\Saas\Resolver;

use Closure;
use Loongs\Orm\Connection\ConnectionConfig;
use Loongs\Saas\Tenant;
use Loongs\Saas\TenantResolver;

/**
 * Closure(string $id): Tenant | connection spec | null — e.g. a lookup in the central database:
 *
 *   new CallbackTenantResolver(static fn (string $id) => ($row = Shop::find($id)) === null ? null
 *       : Tenant::make($id, ['database' => $row->db, …], ['plan' => $row->plan]));
 */
final readonly class CallbackTenantResolver implements TenantResolver
{
    /** @param Closure(string): (Tenant|string|array<string, mixed>|ConnectionConfig|null) $callback */
    public function __construct(private Closure $callback)
    {
    }

    public function resolve(string $id): ?Tenant
    {
        $t = ($this->callback)($id);
        if ($t === null) {
            return null;
        }

        return $t instanceof Tenant ? $t : Tenant::make($id, $t);
    }
}
