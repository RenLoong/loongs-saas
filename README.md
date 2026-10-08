# loongs/saas

SaaS multi-tenancy for [`loongs/orm`](https://github.com/RenLoong/loongs-orm) on the loong-swoole stack (PHP 8.4,
Swoole coroutines): coroutine-local **tenant scopes**, a **tenant registry / resolver**, and **one connection pool per
tenant**, separate from the framework and ORM pools. Built only on loongs/orm's public extension points
(`Orm::resolveDefaultUsing()`, `Orm::addLeaseProvider()`); loongs/orm does not depend on this package.

```bash
composer require loongs/saas:dev-main   # pulls loongs/orm; in the monorepo via a path repo (see "Development")
```

## Layout

```
src/
  Tenancy.php                 facade: resolver, run()/central()/go(), current tenant, connection/table/acquire/using/transaction, pool
  Tenant.php                  immutable tenant: id + connection (name / config array / DSN / ConnectionConfig) + attributes
  TenantResolver.php          id → Tenant lookup (interface)   Resolver/{ArrayTenantResolver,CallbackTenantResolver}.php
  TenantPool.php              per-tenant pools (loongs/orm LeaseProvider over a ConnectionPool, one bucket per tenant)
  TenantPoolConfig.php        size / max_tenants / idle_seconds / ttl / wait_timeout / validate
  TenantScope.php             coroutine-local scope (internal)
  Exceptions/{TenantNotFoundException,NoTenantException}.php
tests/  saas_test.php bootstrap.php models.php   real-MySQL tenant isolation suite (composer test)
```

## Quick start

```php
use Loongs\Saas\Tenancy;
use Loongs\Saas\Tenant;

// 1. how tenant ids are found (once per worker): array, closure or a TenantResolver
Tenancy::resolveUsing(fn (string $id): ?Tenant => ($row = Tenancy::central(fn () => Account::where('slug', $id)->first()))
    ? Tenant::make($id, ['driver' => 'mysql', 'host' => $row->db_host, 'database' => $row->db_name,
        'username' => $row->db_user, 'password' => $row->db_pass, 'prefix' => $row->prefix ?? ''], ['plan' => $row->plan])
    : null);

// 2. per request: scope the whole handler (tenant middleware)
return Tenancy::run($tenantId, function (Tenant $t) use ($request) {
    $user = User::where('email', $request->post('email'))->firstOrFail();   // → tenant DB, tenant pool
    $user->posts()->create(['title' => 'hi']);                               // → tenant DB
    Plan::find($user->plan_id);          // Plan declares $connection = 'central' → central DB (framework pool)
    Tenancy::central(fn () => Audit::create([...]));                         // no tenant: default connection
    return Orm::transaction(fn () => Invoice::create([...]));               // tenant transaction, one connection
});

// 3. explicit, no scope
User::on(Tenancy::config('acme'))->count();
Tenancy::table('invoices', 'acme')->where('paid', 0)->count();

// 4. background work that must keep the tenant (plain go() / Coroutine::create start with NO tenant)
Tenancy::go(fn () => Audit::create([...]));
```

A tenant may be given as: a `Tenant`; a **tenant id** (looked up by the resolver; without one / not found, a
configured connection of that name); a URL / PDO DSN, config array or `ConnectionConfig` (id = database name, or
`database/prefix`). Unknown ids throw `TenantNotFoundException`; `Tenancy::config()` / `connection()` / … with
`null` outside a scope throw `NoTenantException`.

## Guarantees

* **Per-call connection, nothing on the model.** Models / handles keep a `ConnectionConfig`, never a PDO. A model
  loaded or first saved on tenant A still saves / deletes / loads relations on A inside a scope of tenant B; models
  with a declared connection (`protected ?string $connection = 'central'`) ignore the scope.
* **Scope is coroutine-local.** Stored in the coroutine context: private to the coroutine, nestable, restored when
  the callback returns *or throws*, not inherited by child coroutines (use `Tenancy::go()`). Outside coroutines it
  is a plain static stack with the same semantics.
* **Everything pooled.** Calls without a tenant are untouched by this package: named connections use the
  framework `PDOPool` (when booted) and anything else the ORM pool. Every tenant gets **its own bounded pool**
  (`TenantPool`, a bucket keyed by the tenant's full config fingerprint, credentials included): a connection opened
  for one tenant can never be handed to another, and tenant traffic never enters the framework / ORM pools. A
  tenant defined by a connection *name* is copied to an ad-hoc config, so the name keeps its framework pool for
  non-tenant use.
* **Returned manually or automatically.** Per statement / transaction automatically; manually with
  `Tenancy::acquire()` + `release()` (or `using()`); on exceptions the connection is rolled back and returned;
  leaked leases are reclaimed at coroutine end (with a warning).
* **Broken connections discarded.** Lost / killed / out-of-sync connections (and failed rollbacks) are closed, never
  reused; the pool opens replacements. Checkout validates `SELECT DATABASE()` (a `USE other_db` can not leak).
* Process-wide state is configuration only (resolver, pool settings, tenant registrations, the pools); no request
  state outside the coroutine context.

## API

| call | |
|---|---|
| `Tenancy::resolveUsing($resolver)` | `TenantResolver`, `Closure(string $id): Tenant\|spec\|null`, or `['id' => Tenant\|spec]`; `null` removes it |
| `Tenancy::run($tenant, fn (Tenant $t) => …)` | run inside a tenant scope |
| `Tenancy::central(fn () => …)` | run with no tenant (default / central connection) |
| `Tenancy::go(fn () => …)` | `Coroutine::create` carrying the current scope |
| `Tenancy::current()` / `id()` / `active()` / `connectionConfig()` | current `Tenant`, its id, in a scope?, its `ConnectionConfig` (null outside) |
| `Tenancy::tenant($t)` / `find($id)` | normalise to a `Tenant` / resolver lookup only |
| `Tenancy::config($t = null)` | registered `ConnectionConfig` of a tenant (for `Model::on()`, `Orm::table()` …) |
| `Tenancy::connection($t = null)` / `table($table, $t = null)` | connection handle / query builder on a tenant |
| `Tenancy::acquire($t = null)` / `using($t, fn (Connection $c) => …)` | manual lease of a tenant connection (`release()`; `using` always releases) |
| `Tenancy::transaction(fn (Connection $c) => …, $t = null, $attempts = 1)` | transaction on a tenant |
| `Tenancy::configurePool([...])` / `pool()` / `resolvePoolConfig()` | tenant pool settings / the worker's `TenantPool` |
| `Tenancy::poolStats($t = null)` | totals (`tenants, registered, open, active, idle, waiting, created, closed, hits, discarded, waits, timeouts, evicted_tenants`) or per tenant |
| `Tenancy::forget($t)` | offboard a tenant from this worker (pool retired, config no longer routed) |
| `Tenancy::install()` / `uninstall()` / `installed()` / `reset()` | hook into / out of the Orm resolver (done lazily on first use; `reset()` for tests) |

Plain `Orm::*` and models keep working inside a scope: `Orm::table()`, `Orm::connection()`, `Orm::transaction()`,
`Orm::acquire()` and models without a bound / declared connection use the current tenant; `Orm::prefix()`,
`Orm::tableName()`, `Orm::config()` report the tenant's prefix / config.

## Tenant pool settings

`Tenancy::configurePool([...])` → `config('database.tenant_pool')` → `TENANT_POOL_*` env → defaults:

| key | env | default | meaning |
|---|---|---|---|
| `size` | `TENANT_POOL_SIZE` | 8 | max open connections **per tenant** (checked out + idle) |
| `max_tenants` | `TENANT_POOL_MAX_TENANTS` | 64 | tenants keeping connections open; the least recently used idle one is closed |
| `idle_seconds` | `TENANT_POOL_IDLE_SECONDS` | 60 | idle connections older than this are closed |
| `ttl` | `TENANT_POOL_TTL` | 600 | connections older than this are closed (0 = no limit) |
| `wait_timeout` | `TENANT_POOL_WAIT_TIMEOUT` | 3 | seconds to wait for a free connection, then `PoolExhaustedException` (-1 = forever) |
| `validate` | `TENANT_POOL_VALIDATE` | true | checkout check: not in a transaction + `SELECT DATABASE()` matches |

```php
// config/database.php (the same key the tenant-aware loongs/orm used, so existing configs keep working)
'tenant_pool' => ['size' => 8, 'max_tenants' => 64, 'idle_seconds' => 60, 'ttl' => 600, 'wait_timeout' => 3],
```

Sizing: worst case per worker ≈ `max_tenants × size` tenant connections + the framework pools + the ORM pool — keep
it below MySQL `max_connections / workers`. `max_tenants` is soft (a new tenant still gets a pool when every other
tenant has connections checked out). Tenants whose configs are identical share one pool. Changing a registered
tenant's connection (credential rotation, moved database) retires its old pool: idle connections close now,
checked-out ones when they come back.

## Upgrading from the tenant-aware loongs/orm (breaking)

| before (loongs/orm) | now (loongs/saas) |
|---|---|
| `Orm::tenant($spec, fn () => …)` | `Tenancy::run($spec, fn () => …)` — `$spec` may still be a config array / DSN / `ConnectionConfig`; or register ids with `resolveUsing()` and pass the id |
| `Orm::currentTenant()` (`?ConnectionConfig`) | `Tenancy::connectionConfig()`; also `Tenancy::current()` (`?Tenant`), `id()`, `active()` |
| `Orm::go(fn () => …)` | `Tenancy::go(fn () => …)` |
| `Loongs\Orm\Connection\TenantPool` (`Orm::pool()`) | `Loongs\Saas\TenantPool` (`Tenancy::pool()`); `Orm::pool()` is now a generic `ConnectionPool` for non-tenant ad-hoc configs |
| `Orm::configurePool(['max_tenants' => …])` for tenants | `Tenancy::configurePool(['max_tenants' => …])` |
| `config('database.tenant_pool')` read by the ORM | read by loongs/saas (the ORM pool now reads `database.orm_pool`, key `max_pools`) |
| env `ORM_POOL_*` for tenants | `TENANT_POOL_*` |
| `Orm::poolStats()` (`tenants`, `evicted_tenants`) | `Tenancy::poolStats()` (same keys) — `Orm::poolStats()` now reports `pools`, `evicted_pools` |
| tenant leases: `LeaseSource::Pool` | `LeaseSource::Provider` |
| `User::on($tenantArray)` | unchanged; served by the tenant pool once that tenant is registered (`Tenancy::run` / `config` / `tenant`), else by the ORM pool |

Everything else (models, relations, events, `Orm::transaction()`, `Orm::acquire()` / `using()`, prefixes,
`Model::on()`) is unchanged. Packages that "follow the tenant scope" by using the default connection (e.g.
loongs/oauth `new OrmStorage()`) follow `Tenancy::run()` without changes.

## Development

```bash
cd composer/saas && composer test     # = php -d disable_functions= tests/saas_test.php cli && … co
# needs MySQL; credentials from DB_SOCKET / DB_USERNAME / DB_PASSWORD or LOONGS_TEST_ENV=/path/to/.env;
# throwaway databases loongs_saas_* are dropped at the end. Autoload: vendor/autoload.php after `composer install`,
# else the sibling ../orm, ../helper, ../framework sources (monorepo composer/ directory).
```

In the loong-swoole monorepo link it like the other packages (path repo in `server/composer.dev.json`).

License: MIT
