<?php

declare(strict_types=1);

namespace Modright;

final class Storage
{
    public static function catalogIconPath(string $projectId): string
    { if(!preg_match('/^[A-Za-z0-9_-]{1,100}$/',$projectId))throw new \InvalidArgumentException('Invalid project identifier.');$directory=MODRIGHT_ROOT.'/storage/catalog-icons';if(!is_dir($directory)&&!mkdir($directory,0770,true)&&!is_dir($directory))throw new \RuntimeException('Cannot create icon cache.');return$directory.'/'.$projectId; }

    public static function packPath(string $id, string $suffix = ''): string
    {
        if (!preg_match('/^[a-f0-9-]{36}$/', $id)) throw new \InvalidArgumentException('Invalid identifier.');
        return MODRIGHT_ROOT . '/storage/packs/' . $id . ($suffix !== '' ? '/' . ltrim($suffix, '/') : '');
    }

    public static function ensurePack(string $id): void
    {
        foreach (['', 'mods', 'overrides', 'server-overrides', 'client-overrides', 'temp'] as $dir) {
            $path = self::packPath($id, $dir);
            if (!is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) throw new \RuntimeException('Cannot create pack storage.');
        }
    }

    /** @param array<string, mixed> $index */
    public static function writeIndex(string $id, array $index): void
    {
        self::ensurePack($id);
        self::atomicWrite(self::packPath($id, 'modrinth.index.json'), PackRepository::encode($index));
    }

    public static function atomicWrite(string $path, string $contents): void
    {
        $temp = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temp, $contents, LOCK_EX) === false || !rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Could not write file safely.');
        }
    }

    public static function deleteTree(string $path): void
    {
        $root = realpath(MODRIGHT_ROOT . '/storage');
        $real = realpath($path);
        if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($real);
    }
}
