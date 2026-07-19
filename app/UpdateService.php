<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class UpdateService
{
    private const API='https://api.github.com/repos/bastrian/Cogwork-Engine/releases?per_page=20';
    private \Closure $fetcher;
    private bool $customFetcher;

    public function __construct(private readonly PDO $db,private readonly SystemSettings $settings,?callable $fetcher=null,private readonly ?SecretStore$secrets=null)
    { $this->customFetcher=$fetcher!==null;$this->fetcher=$fetcher!==null?\Closure::fromCallable($fetcher):$this->fetch(...); }

    /** @return array<string,mixed> */
    public function check(bool $force=false,bool $allowAutomaticNetwork=false): array
    {
        if(!$this->settings->feature('update_checks'))return['status'=>'disabled','current'=>$this->currentVersion()];
        $cached=$this->cached();$interval=max(1,(int)$this->settings->group('updates')['interval_hours'])*3600;
        $rate=$this->settings->get('system.update_rate',[]);if(is_array($rate)&&!empty($rate['retry_after'])&&!empty($rate['checked_at'])){$retry=is_numeric($rate['retry_after'])?(int)$rate['retry_after']:0;if($retry>0&&strtotime((string)$rate['checked_at'])+$retry>time())return$cached!==null?$this->result($cached,'rate_limited'):['status'=>'rate_limited','current'=>$this->currentVersion(),'retry_after'=>$retry];}
        if(!$force&&$cached!==null&&strtotime((string)$cached['checked_at'])>time()-$interval)return$this->result($cached,'cached');
        if(!$force&&!$allowAutomaticNetwork&&!$this->customFetcher)return$cached!==null?$this->result($cached,'stale'):['status'=>'not_checked','current'=>$this->currentVersion(),'checked_at'=>null];
        if($force&&!$this->customFetcher&&$cached!==null){$last=(int)$this->settings->get('system.update_manual_checked_at',0);if($last>time()-60)return$this->result($cached,'manual_rate_limited');$this->settings->set('system.update_manual_checked_at',time());}
        $headers=[];if($cached){if($cached['etag']!=='')$headers['If-None-Match']=$cached['etag'];if($cached['last_modified']!=='')$headers['If-Modified-Since']=$cached['last_modified'];}
        try{$response=($this->fetcher)(self::API,$headers);$this->storeRateMetadata($response);$status=(int)($response['status']??0);if($status===304&&$cached!==null){$this->store($cached['payload'],$cached['etag'],$cached['last_modified'],'');$this->settings->set('system.update_last_success',Database::now());return$this->result($this->cached(),'not_modified');}if($status!==200)throw new \RuntimeException('GitHub returned HTTP '.$status.'.');$payload=(string)($response['body']??'');$decoded=json_decode($payload,true,512,JSON_THROW_ON_ERROR);if(!is_array($decoded))throw new \RuntimeException('Invalid release response.');$this->store($payload,(string)($response['etag']??''),(string)($response['last_modified']??''),'');$this->settings->set('system.update_last_success',Database::now());return$this->result($this->cached(),'live');}
        catch(\Throwable$e){$this->settings->set('system.update_last_failure',Database::now());if($cached!==null){$this->store($cached['payload'],$cached['etag'],$cached['last_modified'],$e->getMessage());$result=$this->result($this->cached(),'stale');$result['error']=$e->getMessage();return$result;}return['status'=>'unavailable','current'=>$this->currentVersion(),'error'=>$e->getMessage()];}
    }

    /** @return array<string,mixed> */
    private function result(array $cache,string $source): array
    {
        $releases=json_decode((string)$cache['payload'],true);$channel=$this->channel();$selected=null;
        foreach(is_array($releases)?$releases:[]as$release){if(!is_array($release)||!empty($release['draft']))continue;if($channel==='stable'&&!empty($release['prerelease']))continue;$version=ltrim((string)($release['tag_name']??''),'v');if(!preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/',$version))continue;if($selected===null||version_compare($version,$selected['version'],'>')){$assets=$this->assets($release['assets']??[]);$zip=false;$checksum=false;$githubDigest=false;foreach($assets as$asset){if(str_ends_with($asset['name'],'.zip'))$zip=true;if(str_ends_with($asset['name'],'.zip.sha256'))$checksum=true;if($asset['digest']!=='')$githubDigest=true;}$body=(string)($release['body']??'');$notes=trim(strip_tags(preg_replace('/<!--\s*cogwork-requirements:.*?-->/s','',$body)??$body));$selected=['version'=>$version,'tag'=>(string)$release['tag_name'],'name'=>(string)($release['name']??$release['tag_name']),'url'=>$this->trustedUrl((string)($release['html_url']??'')),'published_at'=>$release['published_at']??null,'prerelease'=>(bool)($release['prerelease']??false),'notes'=>mb_substr($notes,0,500),'requirements'=>$this->requirements($body),'assets'=>$assets,'asset_status'=>['zip'=>$zip,'sha256'=>$checksum,'github_digest'=>$githubDigest]];}}
        $current=$this->currentVersion();return['status'=>$selected?'ok':'none','source'=>$source,'current'=>$current,'release'=>$selected,'update_available'=>$selected!==null&&version_compare($selected['version'],$current,'>'),'checked_at'=>$cache['checked_at'],'last_error'=>$cache['last_error']];
    }

    /** @return list<array<string,mixed>> */
    private function assets(mixed $assets): array
    { $result=[];foreach(is_array($assets)?$assets:[]as$asset){$name=(string)($asset['name']??'');if(!preg_match('/^cogwork-engine-[0-9A-Za-z.-]+\.zip(?:\.sha256)?$/',$name))continue;$url=$this->trustedUrl((string)($asset['browser_download_url']??''));if($url!=='')$result[]=['name'=>$name,'url'=>$url,'size'=>(int)($asset['size']??0),'digest'=>(string)($asset['digest']??'')];}return$result; }

    private function trustedUrl(string $url): string
    { $parts=parse_url($url);return is_array($parts)&&($parts['scheme']??'')==='https'&&in_array(strtolower((string)($parts['host']??'')),['github.com','api.github.com'],true)?$url:''; }

    /** @return array{php?:string,extensions?:list<string>} */
    private function requirements(string$body):array
    { if(!preg_match('/<!--\s*cogwork-requirements:\s*(\{.*?\})\s*-->/s',$body,$match))return[];try{$value=json_decode($match[1],true,16,JSON_THROW_ON_ERROR);}catch(\Throwable){return[];}if(!is_array($value))return[];$result=[];if(isset($value['php'])&&is_string($value['php'])&&preg_match('/^\d+\.\d+(?:\.\d+)?$/',$value['php']))$result['php']=$value['php'];if(isset($value['extensions'])&&is_array($value['extensions']))$result['extensions']=array_values(array_filter(array_unique(array_map('strval',$value['extensions'])),fn($extension)=>preg_match('/^[a-z0-9_]+$/i',$extension))) ;return$result;}

    private function currentVersion(): string
    { $value=is_file(MODRIGHT_ROOT.'/VERSION')?trim((string)file_get_contents(MODRIGHT_ROOT.'/VERSION')):'0.0.0';return preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/',$value)?$value:'0.0.0'; }

    private function channel():string
    { $stored=$this->settings->get('system.updates',null);if(!is_array($stored)&&str_contains($this->currentVersion(),'-'))return'prerelease';return(string)$this->settings->group('updates')['channel']; }

    /** @return array<string,mixed>|null */
    private function cached(): ?array
    { $stmt=$this->db->prepare('SELECT * FROM update_cache WHERE cache_key=?');$stmt->execute(['github-releases']);$row=$stmt->fetch();return$row?:null; }

    private function store(string $payload,string $etag,string $modified,string $error): void
    { $this->db->prepare('DELETE FROM update_cache WHERE cache_key=?')->execute(['github-releases']);$this->db->prepare('INSERT INTO update_cache (cache_key,payload,etag,last_modified,checked_at,last_error) VALUES (?,?,?,?,?,?)')->execute(['github-releases',$payload,$etag,$modified,Database::now(),$error]); }

    /** @param array<string,mixed> $response */
    private function storeRateMetadata(array $response): void
    { $this->settings->set('system.update_rate',['remaining'=>$response['rate_remaining']??null,'reset'=>$response['rate_reset']??null,'retry_after'=>$response['retry_after']??null,'checked_at'=>Database::now()]); }

    /** @param array<string,string> $headers @return array<string,mixed> */
    private function fetch(string $url,array $headers): array
    {
        $responseHeaders=[];$request=['Accept: application/vnd.github+json','X-GitHub-Api-Version: 2022-11-28'];foreach($headers as$key=>$value)$request[]=$key.': '.$value;
        $ch=curl_init($url);$options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_FAILONERROR=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>$request,CURLOPT_USERAGENT=>'Cogwork-Engine/'.$this->currentVersion().' update checker',CURLOPT_HEADERFUNCTION=>static function($curl,string$line)use(&$responseHeaders):int{$length=strlen($line);if(str_contains($line,':')){[$key,$value]=explode(':',$line,2);$responseHeaders[strtolower(trim($key))]=trim($value);}return$length;}];$proxy=$this->settings->group('outbound');if(!empty($proxy['proxy_enabled'])){$type=match((string)$proxy['proxy_type']){'socks5'=>CURLPROXY_SOCKS5_HOSTNAME,'https'=>defined('CURLPROXY_HTTPS')?CURLPROXY_HTTPS:CURLPROXY_HTTP,default=>CURLPROXY_HTTP};$options[CURLOPT_PROXY]=(string)$proxy['proxy_host'];$options[CURLOPT_PROXYPORT]=(int)$proxy['proxy_port'];$options[CURLOPT_PROXYTYPE]=$type;$options[CURLOPT_CONNECTTIMEOUT]=max(2,min(30,(int)$proxy['connect_timeout']));$username=(string)$proxy['proxy_username'];$password=($this->secrets??new SecretStore())->get('outbound.password');if($username!==''||$password!=='')$options[CURLOPT_PROXYUSERPWD]=$username.':'.$password;}curl_setopt_array($ch,$options);$body=curl_exec($ch);if($body===false)throw new \RuntimeException('GitHub update request failed: '.curl_error($ch));return['status'=>(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE),'body'=>$body,'etag'=>$responseHeaders['etag']??'','last_modified'=>$responseHeaders['last-modified']??'','rate_remaining'=>$responseHeaders['x-ratelimit-remaining']??null,'rate_reset'=>$responseHeaders['x-ratelimit-reset']??null,'retry_after'=>$responseHeaders['retry-after']??null];
    }
}
