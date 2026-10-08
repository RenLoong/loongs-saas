<?php

declare(strict_types=1);

/**
 * Autoloading for the package tests (no PHPUnit, no install step needed):
 *   - this package's src/ first (prepended);
 *   - then vendor/autoload.php when the package has been installed with composer;
 *   - else sibling checkouts (../orm, ../helper, ../framework) as in the loong-swoole composer/ directory.
 * loongs/framework is optional (the framework-pool sections are skipped without it).
 */

$pkg = dirname(__DIR__);
$psr4 = static function (string $prefix, string $dir, bool $prepend = false): void {
    spl_autoload_register(static function (string $class) use ($prefix, $dir): void {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }, true, $prepend);
};
$psr4('Loongs\\Saas\\', $pkg . '/src', true);
if (is_file($pkg . '/vendor/autoload.php')) {
    require $pkg . '/vendor/autoload.php';
} else {
    $psr4('Loongs\\Orm\\', dirname($pkg) . '/orm/src');
    $psr4('Loongs\\Helper\\', dirname($pkg) . '/helper/src');
    $psr4('Loongs\\', dirname($pkg) . '/framework/src');   // optional: Loongs\Database\DatabaseManager, Loongs\Config\Repository
}
