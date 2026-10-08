<?php

declare(strict_types=1);

namespace Loongs\Saas\Exceptions;

/** A tenant was required (Tenancy::config() / connection() / table() without argument) but no tenant scope is active. */
final class NoTenantException extends \LogicException
{
}
