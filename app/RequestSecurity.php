<?php

declare(strict_types=1);

namespace Modright;

final class RequestSecurity
{
    /** @param array<string,mixed> $server @param list<string> $trustedProxies */
    public static function https(array $server,array $trustedProxies=[]): bool
    {
        if(!empty($server['HTTPS'])&&strtolower((string)$server['HTTPS'])!=='off')return true;
        $remote=(string)($server['REMOTE_ADDR']??'');if(!self::trusted($remote,$trustedProxies))return false;
        $proto=strtolower(trim(explode(',',(string)($server['HTTP_X_FORWARDED_PROTO']??''))[0]));return$proto==='https';
    }

    /** @param list<string> $trustedProxies */
    public static function trusted(string $ip,array $trustedProxies): bool
    {
        foreach($trustedProxies as$range){$range=trim($range);if($range===$ip)return true;if(!str_contains($range,'/'))continue;[$network,$bits]=explode('/',$range,2);$packedIp=@inet_pton($ip);$packedNetwork=@inet_pton($network);if($packedIp===false||$packedNetwork===false||strlen($packedIp)!==strlen($packedNetwork))continue;$bits=(int)$bits;$max=strlen($packedIp)*8;if($bits<0||$bits>$max)continue;$bytes=intdiv($bits,8);$remaining=$bits%8;if(substr($packedIp,0,$bytes)!==substr($packedNetwork,0,$bytes))continue;if($remaining===0)return true;$mask=(0xff<<(8-$remaining))&0xff;if((ord($packedIp[$bytes])&$mask)===(ord($packedNetwork[$bytes])&$mask))return true;}return false;
    }

    public static function canonicalUrl(SystemSettings $settings,array $server): string
    {
        $configured=trim((string)$settings->group('security')['canonical_url']);if($configured!=='')return rtrim($configured,'/');$host=(string)($server['HTTP_HOST']??'localhost');$scheme=self::https($server,$settings->group('security')['trusted_proxies'])?'https':'http';return$scheme.'://'.$host;
    }

    public static function logoutTarget(string$target,string$fallback):string
    {
        if($target===''||$target!==trim($target)||str_contains($target,'\\')||preg_match('/[\x00-\x20\x7F]/',$target))return$fallback;
        if(str_starts_with($target,'/')){if(str_starts_with($target,'//'))return$fallback;$parts=parse_url($target);return is_array($parts)&&!isset($parts['scheme'])&&!isset($parts['host'])&&!isset($parts['user'])&&!isset($parts['pass'])?$target:$fallback;}
        $parts=parse_url($target);if(!is_array($parts)||($parts['scheme']??'')!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']))return$fallback;$host=(string)$parts['host'];if(filter_var($host,FILTER_VALIDATE_IP)===false&&filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)===false)return$fallback;if(isset($parts['port'])&&((int)$parts['port']<1||(int)$parts['port']>65535))return$fallback;return$target;
    }
}
