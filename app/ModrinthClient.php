<?php

declare(strict_types=1);

namespace Modright;

final class ModrinthClient
{
    private const API = 'https://api.modrinth.com/v2';
    private const HOSTS = [
        'api.modrinth.com', 'cdn.modrinth.com',
        'github.com', 'raw.githubusercontent.com',
        'objects.githubusercontent.com', 'release-assets.githubusercontent.com',
        'gitlab.com',
    ];

    /** @return array<string, mixed> */
    public function version(string $id): array
    {
        return $this->json(self::API . '/version/' . rawurlencode($id));
    }

    /** @return array<string, mixed> */
    public function project(string $id): array
    {
        return $this->json(self::API . '/project/' . rawurlencode($id));
    }

    /** @return list<array<string, mixed>> */
    public function projectVersions(string $projectId, string $gameVersion, string $loader): array
    {
        $loader = match ($loader) {'fabric' => 'fabric', 'quilt' => 'quilt', default => $loader};
        $query = http_build_query(['game_versions' => json_encode([$gameVersion]), 'loaders' => json_encode([$loader]), 'include_changelog' => 'false']);
        $result = $this->json(self::API . '/project/' . rawurlencode($projectId) . '/version?' . $query);
        return array_is_list($result) ? $result : [];
    }

    /** @return list<array<string,mixed>> */
    public function searchProjects(string $query,string $gameVersion,string $loader,int $limit=20): array
    { $facets=[['project_type:mod'],['versions:'.$gameVersion],['categories:'.$loader]];$url=self::API.'/search?'.http_build_query(['query'=>$query,'facets'=>json_encode($facets),'limit'=>max(1,min(50,$limit))]);$result=$this->json($url);return is_array($result['hits']??null)?$result['hits']:[]; }

    /** @return array<string, mixed> */
    private function json(string $url): array
    {
        $body = $this->request($url, 10 * 1024 * 1024);
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new \RuntimeException('Unexpected Modrinth response.');
        return $decoded;
    }

    public function download(string $url, string $destination, int $maxBytes = 1073741824): void
    {
        for ($redirects = 0; $redirects <= 3; $redirects++) {
            self::assertUrl($url);
            $temp = $destination . '.part-' . bin2hex(random_bytes(5));
            $file = fopen($temp, 'wb');
            if (!$file) throw new \RuntimeException('Cannot open temporary download.');
            $headers = [];
            $ch = $this->handle($url);
            curl_setopt($ch, CURLOPT_FAILONERROR, false);
            curl_setopt($ch, CURLOPT_FILE, $file);
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($handle, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                return strlen($line);
            });
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, static function ($resource, float $total, float $now) use ($maxBytes): int {
                return ($total > $maxBytes || $now > $maxBytes) ? 1 : 0;
            });
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch); fclose($file);
            if ($ok === false) { @unlink($temp); throw new \RuntimeException('Download failed: ' . $error); }
            if ($status >= 300 && $status < 400 && isset($headers['location'])) {
                @unlink($temp);
                if ($redirects === 3) throw new \RuntimeException('Download exceeded the redirect limit.');
                $url = self::redirectUrl($url, $headers['location']);
                continue;
            }
            if ($status === 429) { @unlink($temp); throw new \RuntimeException('Modrinth rate limit reached. Try again later.'); }
            if ($status < 200 || $status >= 300) { @unlink($temp); throw new \RuntimeException("Download returned HTTP {$status}."); }
            if (filesize($temp) > $maxBytes || !rename($temp, $destination)) {
                @unlink($temp); throw new \RuntimeException('Download exceeded its limit or could not be saved.');
            }
            return;
        }
    }

    public static function redirectUrl(string $base, string $location): string
    {
        if (str_starts_with($location, 'https://')) return $location;
        $parts = parse_url($base);
        if (str_starts_with($location, '//')) return 'https:' . $location;
        if (str_starts_with($location, '/')) return 'https://' . $parts['host'] . $location;
        $path = $parts['path'] ?? '/';
        return 'https://' . $parts['host'] . rtrim(dirname($path), '/') . '/' . $location;
    }

    public static function assertUrl(string $url): void
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || !in_array(strtolower($parts['host'] ?? ''), self::HOSTS, true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Only approved HTTPS download hosts are allowed.');
        }
    }

    private function request(string $url, int $limit): string
    {
        self::assertUrl($url);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $headers=[];$ch=$this->handle($url);
            curl_setopt($ch,CURLOPT_FAILONERROR,false);curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);curl_setopt($ch,CURLOPT_MAXFILESIZE,$limit);
            curl_setopt($ch,CURLOPT_HEADERFUNCTION,static function($handle,string$line)use(&$headers):int{$parts=explode(':',$line,2);if(count($parts)===2)$headers[strtolower(trim($parts[0]))]=trim($parts[1]);return strlen($line);});
            $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
            if($body!==false&&$status>=200&&$status<300){if(strlen($body)>$limit)throw new \RuntimeException('Response exceeded its limit.');return$body;}
            if($status===429&&$attempt<2){$wait=max(1,min(30,(int)($headers['retry-after']??$headers['x-ratelimit-reset']??1)));sleep($wait);continue;}
            if($body===false&&$attempt<2){usleep((int)(250000*(2**$attempt)));continue;}
            if($status===429)throw new \RuntimeException('Modrinth rate limit reached. Try again later.');
            throw new \RuntimeException($status>0?"Modrinth API returned HTTP {$status}.":'Modrinth request failed: '.$error);
        }
        throw new \RuntimeException('Modrinth request failed.');
    }

    private function handle(string $url): \CurlHandle
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'Cogwork-Engine/1.0 (shared-hosting modpack manager)', CURLOPT_FAILONERROR => true, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        return $ch;
    }
}
