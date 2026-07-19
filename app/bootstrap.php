<?php

declare(strict_types=1);

define('MODRIGHT_ROOT', dirname(__DIR__));

if (is_file(MODRIGHT_ROOT . '/vendor/autoload.php')) {
    require_once MODRIGHT_ROOT . '/vendor/autoload.php';
}

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
    $trustedProxies=array_values(array_filter(array_map('trim',explode(',',(string)getenv('COGWORK_TRUSTED_PROXIES')))));
    if(\Modright\Config::installed()){try{$configured=(new \Modright\SystemSettings(\Modright\Database::connect()))->group('security')['trusted_proxies'];if(is_array($configured))$trustedProxies=array_values(array_unique(array_merge($trustedProxies,$configured)));}catch(\Throwable){}}
    $https = \Modright\RequestSecurity::https($_SERVER,is_array($trustedProxies)?$trustedProxies:[]);
    session_name('modright_session');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $https,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}
