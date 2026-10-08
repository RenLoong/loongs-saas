<?php

declare(strict_types=1);

namespace Loongs\Saas;

/**
 * Looks a tenant up by id (central database, config file, cache …). Called on every
 * Tenancy::run('<id>', …) — cache inside the resolver if the lookup is expensive. Must be
 * coroutine-safe: no per-request state.
 */
interface TenantResolver
{
    public function resolve(string $id): ?Tenant;
}
