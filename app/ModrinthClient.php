<?php

declare(strict_types=1);

namespace Modright;

final class ModrinthClient
{
    private const API = 'https://api.modrinth.com/v2';
    public const ALLOWED_HOSTS = [
        'api.modrinth.com', 'cdn.modrinth.com', 'status.modrinth.com',
        'github.com', 'raw.githubusercontent.com',
        'objects.githubusercontent.com', 'release-assets.githubusercontent.com',
        'gitlab.com',
    ];
    public function __construct(private readonly ?SystemSettings $settings=null,private readonly ?SecretStore $secrets=null) {}

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

    /** @return list<array<string,mixed>> */
    public function gameVersions(): array
    { $result=$this->json(self::API.'/tag/game_version');return array_is_list($result)?$result:[]; }

    public function statusPage(): string
    { return $this->request('https://status.modrinth.com/',2*1024*1024,4); }

    /** @return array{status:string,stage:string,message:string,checked_at:string} */
    public function diagnose():array
    { $started=microtime(true);try{$project=$this->project('fabric-api');if(($project['id']??'')==='')throw new \RuntimeException('The API response did not contain the expected project identifier.');return['status'=>'healthy','stage'=>'api','message'=>'DNS, proxy connection, TLS, HTTP, and Modrinth API checks succeeded in '.(int)((microtime(true)-$started)*1000).' ms.','checked_at'=>Database::now()];}catch(\Throwable$e){$message=$e->getMessage();$stage=match(true){str_contains($message,'disabled')=>'disabled',str_contains(strtolower($message),'proxy')=>'proxy',str_contains(strtolower($message),'resolve')=>'dns',str_contains(strtolower($message),'ssl')||str_contains(strtolower($message),'certificate')=>'tls',str_contains($message,'HTTP')=>'http',default=>'connection'};return['status'=>$stage==='disabled'?'disabled':'degraded','stage'=>$stage,'message'=>'Connection test failed at '.strtoupper($stage).'. Review connectivity settings and server logs; credentials and remote response content are not shown.','checked_at'=>Database::now()];} }

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
        if (($parts['scheme'] ?? '') !== 'https' || !in_array(strtolower($parts['host'] ?? ''), self::ALLOWED_HOSTS, true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Only approved HTTPS download hosts are allowed.');
        }
    }

    /** @param mixed $values @return list<string> */
    public static function normalizeProxyBypass(mixed $values): array
    {
        if(!is_array($values))return[];
        $normalized=array_map(static fn(mixed$value):string=>strtolower(trim((string)$value)),$values);
        return array_values(array_unique(array_intersect($normalized,self::ALLOWED_HOSTS)));
    }

    private function request(string $url, int $limit,int$totalTimeout=60): string
    {
        self::assertUrl($url);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $headers=[];$ch=$this->handle($url,$totalTimeout);
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

    private function handle(string $url,int$totalTimeout=60): \CurlHandle
    {
        if($this->settings!==null&&!$this->settings->feature('modrinth'))throw new \RuntimeException('Modrinth connectivity is disabled by the administrator.');
        $ch = curl_init($url);
        $connection=$this->settings?->group('connectivity')??[];$connectTimeout=min($totalTimeout,max(2,min(30,(int)($connection['connect_timeout']??10))));curl_setopt_array($ch, [CURLOPT_CONNECTTIMEOUT => $connectTimeout, CURLOPT_TIMEOUT => max(2,$totalTimeout), CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'Cogwork-Engine/1.0 (shared-hosting modpack manager)', CURLOPT_FAILONERROR => true, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        if(!empty($connection['proxy_enabled'])){$host=trim((string)($connection['proxy_host']??''));$port=(int)($connection['proxy_port']??0);if(!preg_match('/^[A-Za-z0-9.-]+$/',$host)||$port<1||$port>65535)throw new \RuntimeException('Outbound proxy configuration is invalid.');$type=match((string)($connection['proxy_type']??'http')){'socks5'=>CURLPROXY_SOCKS5_HOSTNAME,'https'=>defined('CURLPROXY_HTTPS')?CURLPROXY_HTTPS:CURLPROXY_HTTP,default=>CURLPROXY_HTTP};curl_setopt($ch,CURLOPT_PROXY,$host);curl_setopt($ch,CURLOPT_PROXYPORT,$port);curl_setopt($ch,CURLOPT_PROXYTYPE,$type);$bypass=self::normalizeProxyBypass($connection['bypass']??[]);if($bypass)curl_setopt($ch,CURLOPT_NOPROXY,implode(',',$bypass));$username=(string)($connection['proxy_username']??'');$password=$this->secrets?->get('proxy.password')??'';if($username!==''||$password!=='')curl_setopt($ch,CURLOPT_PROXYUSERPWD,$username.':'.$password);}
        return $ch;
    }
}
