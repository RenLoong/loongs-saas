<?php

declare(strict_types=1);

namespace Loongs\Saas\Exceptions;

/** The tenant id is unknown to the resolver (and is not a configured connection name either). */
final class TenantNotFoundException extends \RuntimeException
{
    public static function forId(string $id): self
    {
        return new self("Tenant [{$id}] not found (Tenancy::resolveUsing() resolver returned nothing and no connection of that name is configured).");
    }
}
