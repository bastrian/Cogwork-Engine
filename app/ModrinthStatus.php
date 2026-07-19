<?php

declare(strict_types=1);

namespace Modright;

final class ModrinthStatus
{
    private const URL = 'https://status.modrinth.com/';
    private const CACHE_SECONDS = 90;

    /** @return array{state:string,label:string,checked_at:string} */
    public function current(): array
    {
        $cachePath = MODRIGHT_ROOT . '/storage/status-cache.json';
        $cached = $this->readCache($cachePath);
        if ($cached !== null && time() - strtotime($cached['checked_at']) < self::CACHE_SECONDS) {
            return $cached;
        }

        try {
            $ch = curl_init(self::URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_FAILONERROR => true,
                CURLOPT_MAXFILESIZE => 2 * 1024 * 1024,
                CURLOPT_USERAGENT => 'Cogwork-Engine/1.0 status indicator',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            ]);
            $html = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);
            if (!is_string($html) || $html === '') throw new \RuntimeException($error ?: 'Empty status response.');
            $status = self::classify($html);
        } catch (\Throwable) {
            $status = ['state' => 'unknown', 'label' => 'Status unknown'];
        }

        $status['checked_at'] = Database::now();
        try {
            Storage::atomicWrite($cachePath, json_encode($status, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            // A status indicator must never break the application.
        }
        return $status;
    }

    /** @return array{state:string,label:string} */
    public static function classify(string $html): array
    {
        if (stripos($html, 'All systems operational') !== false
            || stripos($html, 'All systems are operational') !== false
            || stripos($html, 'All services are online') !== false) {
            return ['state' => 'up', 'label' => 'Modrinth operational'];
        }
        if (stripos($html, 'Some services are down') !== false
            || stripos($html, 'Partial outage') !== false
            || stripos($html, 'Degraded performance') !== false
            || stripos($html, 'Major outage') !== false
            || stripos($html, 'Under maintenance') !== false) {
            return ['state' => 'issues', 'label' => 'Modrinth issues'];
        }
        return ['state' => 'unknown', 'label' => 'Status unknown'];
    }

    /** @return array{state:string,label:string,checked_at:string}|null */
    private function readCache(string $path): ?array
    {
        if (!is_file($path)) return null;
        try {
            $value = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($value) || !isset($value['state'], $value['label'], $value['checked_at'])) return null;
            return $value;
        } catch (\Throwable) {
            return null;
        }
    }
}
