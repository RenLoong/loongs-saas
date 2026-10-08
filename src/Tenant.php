<?php

declare(strict_types=1);

namespace Loongs\Saas;

use InvalidArgumentException;
use Loongs\Orm\Connection\ConnectionConfig;
use Loongs\Orm\Orm;

/**
 * A tenant: an id plus the database it lives in (connection name, config array, URL / PDO DSN or
 * ConnectionConfig) and optional host attributes (plan, domain, …). Immutable value object — it
 * holds a connection *description*, never a PDO.
 */
final readonly class Tenant
{
    /**
     * @param string|array<string, mixed>|ConnectionConfig $connection
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public string $id,
        public string|array|ConnectionConfig $connection,
        public array $attributes = [],
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('Tenant id must not be empty.');
        }
    }

    /**
     * @param string|array<string, mixed>|ConnectionConfig $connection
     * @param array<string, mixed> $attributes
     */
    public static function make(string $id, string|array|ConnectionConfig $connection, array $attributes = []): self
    {
        return new self($id, $connection, $attributes);
    }

    /**
     * A tenant described only by its connection (migration path from Orm::tenant($spec, …)).
     * The id defaults to "<database>" or "<database>/<prefix>" (else the config key) — pass $id when
     * several tenants share a database and prefix.
     *
     * @param string|array<string, mixed>|ConnectionConfig $connection
     */
    public static function fromConnection(string|array|ConnectionConfig $connection, ?string $id = null): self
    {
        if ($id === null) {
            $cfg = Orm::config($connection);
            $id = $cfg->database() === '' ? $cfg->key : $cfg->database() . ($cfg->prefix() !== '' ? '/' . $cfg->prefix() : '');
        }

        return new self($id, $connection);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * The tenant's connection config as used by the tenant pool: always an ad-hoc config (a named
     * connection is copied), so it never shares a key with the named connection itself — the
     * framework / ORM pools keep serving non-tenant uses of that name. Resolved on every call.
     */
    public function connectionConfig(): ConnectionConfig
    {
        $cfg = Orm::config($this->connection);

        return $cfg->isNamed() ? ConnectionConfig::adhoc($cfg->config) : $cfg;
    }
}
