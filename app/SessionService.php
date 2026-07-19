<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class SessionService
{
    public function __construct(private readonly PDO$db,private readonly string$key,private readonly ?SystemSettings$settings=null){}

    /** @param list<string> $methods @return array{id:string,token:string} */
    public function create(string$userId,string$label,string$ip,array$methods,int$hours=168):array
    {
        $policy=($this->settings??new SystemSettings($this->db))->group('security');$role=$this->db->prepare('SELECT role FROM users WHERE id=?');$role->execute([$userId]);$admin=$role->fetchColumn()==='admin';$configured=max(1,(int)($policy['session_absolute_hours']??$hours));$hours=$admin?min(24,$configured):$configured;
        $id=Database::id();$token=bin2hex(random_bytes(32));$now=Database::now();$expires=gmdate('c',time()+$hours*3600);$stmt=$this->db->prepare('INSERT INTO user_sessions (id,user_id,token_hash,label,ip_hash,created_at,last_seen_at,expires_at,auth_methods) VALUES (?,?,?,?,?,?,?,?,?)');$stmt->execute([$id,$userId,$this->hash($token),$this->deviceLabel($label),$this->hash($this->network($ip)),$now,$now,$expires,json_encode(array_values(array_unique($methods)),JSON_THROW_ON_ERROR)]);return['id'=>$id,'token'=>$token];
    }

    /** @return array<string,mixed>|null */
    public function verify(string$token):?array
    {
        $stmt=$this->db->prepare('SELECT s.*,u.enabled,u.session_version,u.role FROM user_sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=? AND s.revoked_at IS NULL AND s.expires_at>?');$stmt->execute([$this->hash($token),Database::now()]);$session=$stmt->fetch();if(!$session||(int)$session['enabled']!==1)return null;
        $policy=($this->settings??new SystemSettings($this->db))->group('security');$idle=max(5,(int)($policy['session_idle_minutes']??720));if($session['role']==='admin')$idle=min(120,$idle);if(strtotime((string)$session['last_seen_at'])<time()-$idle*60){$this->db->prepare('UPDATE user_sessions SET revoked_at=? WHERE id=? AND revoked_at IS NULL')->execute([Database::now(),$session['id']]);return null;}return$session;
    }

    /** @return list<array<string,mixed>> */
    public function forUser(string$userId):array
    { $stmt=$this->db->prepare('SELECT id,label,created_at,last_seen_at,expires_at,revoked_at,auth_methods FROM user_sessions WHERE user_id=? ORDER BY last_seen_at DESC');$stmt->execute([$userId]);return$stmt->fetchAll(); }

    public function touch(string$id):void{$this->db->prepare('UPDATE user_sessions SET last_seen_at=? WHERE id=? AND revoked_at IS NULL')->execute([Database::now(),$id]);}
    public function revoke(string$id,string$userId):void{$this->db->prepare('UPDATE user_sessions SET revoked_at=? WHERE id=? AND user_id=? AND revoked_at IS NULL')->execute([Database::now(),$id,$userId]);}
    public function revokeAll(string$userId,?string$exceptId=null):int{$sql='UPDATE user_sessions SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL'.($exceptId!==null?' AND id<>?':'');$stmt=$this->db->prepare($sql);$params=[Database::now(),$userId];if($exceptId!==null)$params[]=$exceptId;$stmt->execute($params);return$stmt->rowCount();}
    private function hash(string$value):string{return hash_hmac('sha256',$value,$this->key);}
    private function network(string$ip):string
    { if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){[$a,$b,$c]=array_pad(explode('.',$ip),3,'0');return"{$a}.{$b}.{$c}.0/24";}if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)){$packed=inet_pton($ip);if($packed!==false)return bin2hex(substr($packed,0,8)).'/64';}return'unknown'; }
    private function deviceLabel(string$userAgent):string
    { $browser=match(true){preg_match('/Edg\/([0-9]+)/',$userAgent,$m)===1=>'Edge '.$m[1],preg_match('/Firefox\/([0-9]+)/',$userAgent,$m)===1=>'Firefox '.$m[1],preg_match('/(?:Chrome|CriOS)\/([0-9]+)/',$userAgent,$m)===1=>'Chrome '.$m[1],preg_match('/Version\/([0-9.]+).*Safari\//',$userAgent,$m)===1=>'Safari '.$m[1],default=>'Browser'};$os=match(true){str_contains($userAgent,'Windows')=>'Windows',str_contains($userAgent,'Android')=>'Android',str_contains($userAgent,'iPhone')||str_contains($userAgent,'iPad')=>'iOS',str_contains($userAgent,'Mac OS X')=>'macOS',str_contains($userAgent,'Linux')=>'Linux',default=>''};return$browser.($os!==''?' on '.$os:''); }
}
