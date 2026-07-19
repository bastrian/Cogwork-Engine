<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class AuthThrottle
{
    public function __construct(private readonly PDO $db,private readonly string $key) {}

    /** @return array{allowed:bool,retry_after:int,failures:int} */
    public function check(string $scope,string $subject,string $ip,int $limit=8,int $window=900): array
    {
        $subjectHash=$this->hash(mb_strtolower(trim($subject)));$ipHash=$this->hash($this->network($ip));$cutoff=gmdate('c',time()-$window);$stmt=$this->db->prepare('SELECT COUNT(*) FROM auth_attempts WHERE scope=? AND succeeded=0 AND created_at>=? AND (subject_hash=? OR ip_hash=?)');$stmt->execute([$scope,$cutoff,$subjectHash,$ipHash]);$local=(int)$stmt->fetchColumn();$globalStmt=$this->db->prepare('SELECT COUNT(*) FROM auth_attempts WHERE scope=? AND succeeded=0 AND created_at>=?');$globalStmt->execute([$scope,$cutoff]);$global=(int)$globalStmt->fetchColumn();$globalLimit=max(50,$limit*20);$failures=max($local,(int)floor($global/20));$allowed=$local<$limit&&$global<$globalLimit;$delay=$allowed?0:min(3600,30*(2**min(7,max(0,$failures-$limit))));return['allowed'=>$allowed,'retry_after'=>$delay,'failures'=>$failures];
    }

    public function record(string $scope,string $subject,string $ip,bool $succeeded): void
    { $subjectHash=$this->hash(mb_strtolower(trim($subject)));$ipHash=$this->hash($this->network($ip));if($succeeded){$clear=$this->db->prepare('DELETE FROM auth_attempts WHERE scope=? AND subject_hash=? AND succeeded=0');$clear->execute([$scope,$subjectHash]);}$stmt=$this->db->prepare('INSERT INTO auth_attempts (id,scope,subject_hash,ip_hash,succeeded,created_at) VALUES (?,?,?,?,?,?)');$stmt->execute([Database::id(),$scope,$subjectHash,$ipHash,$succeeded?1:0,Database::now()]); }

    public function prune(int $days=30): int
    { $stmt=$this->db->prepare('DELETE FROM auth_attempts WHERE created_at<?');$stmt->execute([gmdate('c',time()-max(1,$days)*86400)]);return$stmt->rowCount(); }

    public function pseudonymousNetwork(string $ip): string
    { return $this->hash($this->network($ip)); }

    private function hash(string $value): string{return hash_hmac('sha256',$value,$this->key);}
    private function network(string$ip):string
    { if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){[$a,$b,$c]=array_pad(explode('.',$ip),3,'0');return"{$a}.{$b}.{$c}.0/24";}if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)){$packed=inet_pton($ip);if($packed!==false)return bin2hex(substr($packed,0,8)).'/64';}return'unknown'; }
}
