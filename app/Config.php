<?php

declare(strict_types=1);

namespace Modright;

final class Config
{
    public const FILE = MODRIGHT_ROOT . '/storage/config.php';

    public static function installed(): bool
    {
        return is_file(self::FILE) && is_file(MODRIGHT_ROOT . '/storage/installed.lock');
    }

    /** @return array<string, mixed> */
    public static function load(): array
    {
        if (!self::installed()) {
            throw new \RuntimeException('Application is not installed.');
        }
        $config = require self::FILE;
        if (!is_array($config)) {
            throw new \RuntimeException('Invalid application configuration.');
        }
        return $config;
    }

    /**
     * Host-level maintenance escape hatch. This deliberately lives in the
     * protected installation configuration, outside database-backed settings,
     * so an administrator can recover when the UI is unreachable.
     */
    /** @param array<string,mixed>|null $config */
    public static function maintenanceDisabledByHost(?array $config = null): bool
    {
        if (getenv('COGWORK_MAINTENANCE_DISABLE') === '1') {
            return true;
        }
        if ($config === null) {
            try {
                $config = self::load();
            } catch (\Throwable) {
                return false;
            }
        }
        return ($config['emergency']['disable_maintenance'] ?? false) === true;
    }

    /** @param array<string, mixed> $config */
    public static function write(array $config): void
    {
        self::ensureStorage();
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents(self::FILE, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write storage/config.php.');
        }
        chmod(self::FILE, 0600);
    }

    public static function ensureStorage(): void
    {
        foreach (['storage', 'storage/data', 'storage/packs', 'storage/logs', 'storage/temp', 'storage/packages', 'storage/backups'] as $dir) {
            $path = MODRIGHT_ROOT . '/' . $dir;
            if (!is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) {
                throw new \RuntimeException("Cannot create {$dir}.");
            }
        }
    }
}
