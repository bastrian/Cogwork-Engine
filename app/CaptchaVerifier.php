<?php
declare(strict_types=1);
namespace Modright;
use PDO;
final class CaptchaVerifier
{
    public function __construct(private readonly PDO$db,private readonly CaptchaProvider$provider){}
    public function verify(string$token,string$action,string$hostname):array
    { $hash=hash('sha256',$token);$stmt=$this->db->prepare("SELECT COUNT(*) FROM auth_challenges WHERE purpose='recaptcha' AND challenge_hash=? AND expires_at>?");$stmt->execute([$hash,Database::now()]);if((int)$stmt->fetchColumn()>0)return['accepted'=>false,'score'=>null,'error'=>'replayed_token'];$result=$this->provider->verify($token,$action,$hostname);if($result['accepted']){$now=Database::now();try{$this->db->prepare('INSERT INTO auth_challenges (id,user_id,purpose,challenge_hash,payload,expires_at,used_at,created_at) VALUES (?,?,?,?,?,?,?,?)')->execute([Database::id(),null,'recaptcha',$hash,'{}',gmdate('c',time()+180),$now,$now]);}catch(\PDOException){return['accepted'=>false,'score'=>null,'error'=>'replayed_token'];}}return$result;}
}
