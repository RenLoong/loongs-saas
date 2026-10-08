<?php

declare(strict_types=1);

namespace Loongs\Saas;

use Loongs\Orm\Connection\ConnectionConfig;

/** @internal value kept in the coroutine context while Tenancy::run() executes */
final readonly class TenantScope
{
    public function __construct(
        public Tenant $tenant,
        public ConnectionConfig $config,
    ) {
    }
}
