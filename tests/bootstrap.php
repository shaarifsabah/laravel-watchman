<?php

// Use the package's own vendor/ when installed standalone, otherwise a host app's
// autoloader passed in WATCHMAN_TEST_AUTOLOAD (e.g. /path/to/laravel-app/vendor/autoload.php).
$autoload = getenv('WATCHMAN_TEST_AUTOLOAD') ?: __DIR__ . '/../vendor/autoload.php';
require $autoload;

spl_autoload_register(static function (string $class): void {
    foreach (['Watchman\\Tests\\' => __DIR__ . '/', 'Watchman\\' => __DIR__ . '/../src/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});
