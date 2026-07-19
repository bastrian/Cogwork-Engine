<?php

declare(strict_types=1);

namespace Modright;

final class VersionCatalog
{
    private const TTL = 21600;
    private const MINECRAFT_FALLBACK = ['1.21.8','1.21.7','1.21.6','1.21.5','1.21.4','1.21.3','1.21.2','1.21.1','1.21','1.20.6','1.20.5','1.20.4','1.20.3','1.20.2','1.20.1','1.20','1.19.4','1.19.3','1.19.2','1.19.1','1.19','1.18.2','1.18.1','1.18','1.17.1','1.17','1.16.5'];
    private ?string $notice = null;

    public function notice(): ?string
    {
        return $this->notice;
    }

    /** @return list<string> */
    public function minecraft(): array
    {
        return $this->cached('minecraft', function (): array {
            $items = $this->json('https://api.modrinth.com/v2/tag/game_version', 1024 * 1024);
            $versions = [];
            foreach ($items as $item) {
                if (($item['version_type'] ?? '') === 'release' && !empty($item['version'])) $versions[] = (string) $item['version'];
            }
            return array_values(array_unique($versions));
        }, self::MINECRAFT_FALLBACK);
    }

    /** @return list<string> */
    public function loaders(string $loader, string $minecraft): array
    {
        if (!in_array($loader, ['fabric', 'forge', 'neoforge', 'quilt'], true) || !in_array($minecraft, $this->minecraft(), true)) {
            throw new \InvalidArgumentException('Invalid loader or Minecraft version.');
        }
        return $this->cached('loader-v3-' . $loader . '-' . preg_replace('/[^A-Za-z0-9._-]/', '-', $minecraft), fn (): array => match ($loader) {
            'fabric' => $this->metaLoaders('https://meta.fabricmc.net/v2/versions/loader/' . rawurlencode($minecraft), true),
            'quilt' => $this->metaLoaders('https://meta.quiltmc.org/v3/versions/loader/' . rawurlencode($minecraft), false),
            'forge' => $this->forge($minecraft),
            'neoforge' => $this->neoforge($minecraft),
        });
    }

    public function valid(string $loader, string $minecraft, string $loaderVersion): bool
    {
        return in_array($loaderVersion, $this->loaders($loader, $minecraft), true);
    }

    /** @return list<string> */
    private function metaLoaders(string $url, bool $preferStable): array
    {
        $items = $this->json($url, 2 * 1024 * 1024); $stable=[]; $other=[];
        foreach ($items as $item) {
            $version = $item['loader']['version'] ?? null;
            if (!is_string($version) || $version === '') continue;
            if ($preferStable && !empty($item['loader']['stable'])) $stable[]=$version; else $other[]=$version;
        }
        return array_slice(array_values(array_unique(array_merge($stable,$other))),0,60);
    }

    /** @return list<string> */
    private function forge(string $minecraft): array
    {
        $xml=$this->get('https://maven.minecraftforge.net/net/minecraftforge/forge/maven-metadata.xml',2*1024*1024);preg_match_all('#<version>'.preg_quote($minecraft,'#').'-([^<]+)</version>#',$xml,$matches);return array_values(array_unique(array_reverse($matches[1]??[])));
    }

    /** @return list<string> */
    private function neoforge(string $minecraft): array
    {
        $xml=$this->get('https://maven.neoforged.net/releases/net/neoforged/neoforge/maven-metadata.xml',1024*1024);
        preg_match_all('#<version>([^<]+)</version>#',$xml,$matches);$prefix=$this->neoForgePrefix($minecraft);$versions=[];
        foreach(array_reverse($matches[1]??[])as$version){if(str_starts_with($version,$prefix.'.'))$versions[]=$version;if(count($versions)>=60)break;}
        return$versions;
    }

    public static function neoForgePrefix(string $minecraft): string
    {
        return str_starts_with($minecraft,'1.') ? substr($minecraft,2) : $minecraft;
    }

    /** @return array<mixed> */
    private function json(string $url, int $limit): array
    {
        $value=json_decode($this->get($url,$limit),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($value))throw new \RuntimeException('Unexpected version catalog response.');return$value;
    }

    private function get(string $url, int $limit): string
    {
        $allowed=['api.modrinth.com','meta.fabricmc.net','meta.quiltmc.org','files.minecraftforge.net','maven.minecraftforge.net','maven.neoforged.net'];$parts=parse_url($url);
        if(($parts['scheme']??'')!=='https'||!in_array(strtolower($parts['host']??''),$allowed,true))throw new \InvalidArgumentException('Unsupported catalog host.');
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_FAILONERROR=>true,CURLOPT_MAXFILESIZE=>$limit,CURLOPT_USERAGENT=>'Cogwork-Engine/1.0 version catalog',CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
        $body=curl_exec($ch);$error=curl_error($ch);curl_close($ch);if(!is_string($body)||strlen($body)>$limit)throw new \RuntimeException('Could not load version catalog: '.$error);return$body;
    }

    /** @param callable():list<string> $loader @param list<string> $fallback @return list<string> */
    private function cached(string $key, callable $loader, array $fallback=[]): array
    {
        $path=MODRIGHT_ROOT.'/storage/catalog-'.$key.'.json';
        if(is_file($path)&&time()-filemtime($path)<self::TTL){$data=json_decode((string)file_get_contents($path),true);if(is_array($data))return$data;}
        try{$data=$loader();if($data===[])throw new \RuntimeException('No compatible versions are available.');Storage::atomicWrite($path,json_encode($data,JSON_THROW_ON_ERROR));return$data;}
        catch(\Throwable$e){if(is_file($path)){$data=json_decode((string)file_get_contents($path),true);if(is_array($data)&&$data!==[]){$this->notice='The live version service is unavailable. Using the last saved catalog.';return$data;}}if($fallback!==[]){$this->notice='Modrinth is unavailable and no saved catalog exists yet. Using Cogwork Engine’s built-in Minecraft version list.';return$fallback;}throw new \RuntimeException('The compatible version list could not be loaded. Please try again shortly.');}
    }
}
