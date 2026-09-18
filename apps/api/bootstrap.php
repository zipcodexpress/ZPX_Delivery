<?php
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Zpx\\')) { return; }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) { require $file; }
});
date_default_timezone_set('UTC');
