<?php

declare(strict_types=1);

define('MODRIGHT_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Modright\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = MODRIGHT_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

if (PHP_VERSION_ID < 80300) {
    http_response_code(500);
    exit('Cogwork Engine requires PHP 8.3 or newer.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('modright_session');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}
