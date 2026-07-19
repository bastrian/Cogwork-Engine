<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class PasswordRecoveryService
{
    public function __construct(private readonly PDO $db,private readonly UserService $users,private readonly AuthThrottle $throttle,private readonly MailService $mail,private readonly SessionService $sessions,private readonly SystemSettings $settings) {}

    public function request(string $email,string $ip): void
    {
        $started=microtime(true);try{$normalized=UserService::normalizeEmail($email);$state=$this->throttle->check('password_request',$normalized,$ip,5,3600);if(!$state['allowed'])return;$this->throttle->record('password_request',$normalized,$ip,false);$user=$this->users->findByEmail($normalized);if($user===null){password_verify('enumeration-resistant-dummy-password','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');return;}
        $minutes=$this->expiryMinutes();$token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$now=Database::now();$expires=gmdate('c',time()+$minutes*60);$this->db->prepare('UPDATE password_reset_tokens SET used_at=? WHERE user_id=? AND used_at IS NULL')->execute([$now,$user['id']]);$this->db->prepare('INSERT INTO password_reset_tokens (id,user_id,token_hash,requested_ip_hash,expires_at,created_at) VALUES (?,?,?,?,?,?)')->execute([Database::id(),$user['id'],$hash,$this->throttle->pseudonymousNetwork($ip),$expires,$now]);$base=rtrim((string)$this->settings->group('security')['canonical_url'],'/');if(!str_starts_with($base,'https://'))throw new \RuntimeException('Password recovery requires a canonical HTTPS URL.');$url=$base.'/index.php?route=password/reset&token='.rawurlencode($token);$this->mail->send((string)$user['email'],'Reset your Cogwork Engine password',"A password reset was requested for your account.\n\n{$url}\n\nThis link expires in {$minutes} minutes and can be used once. If you did not request it, ignore this message.");}finally{$remaining=0.25-(microtime(true)-$started);if($remaining>0)usleep((int)ceil($remaining*1000000));}
    }

    public function requestForUser(string$userId,string$ip):void
    { $user=$this->users->find($userId);if(empty($user['email_verified_at']))throw new \RuntimeException('Verify the account email before initiating password recovery.');$minutes=$this->expiryMinutes();$token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$now=Database::now();$expires=gmdate('c',time()+$minutes*60);$this->db->prepare('UPDATE password_reset_tokens SET used_at=? WHERE user_id=? AND used_at IS NULL')->execute([$now,$userId]);$this->db->prepare('INSERT INTO password_reset_tokens (id,user_id,token_hash,requested_ip_hash,expires_at,created_at) VALUES (?,?,?,?,?,?)')->execute([Database::id(),$userId,$hash,$this->throttle->pseudonymousNetwork($ip),$expires,$now]);$base=rtrim((string)$this->settings->group('security')['canonical_url'],'/');if(!str_starts_with($base,'https://'))throw new \RuntimeException('Password recovery requires a canonical HTTPS URL.');$this->mail->send((string)$user['email'],'Choose a new Cogwork Engine password',"An administrator initiated account recovery.\n\n{$base}/index.php?route=password/reset&token=".rawurlencode($token)."\n\nThis single-use link expires in {$minutes} minutes. Contact your administrator if this was unexpected."); }

    public function reset(string $token,string $password,string $ip): bool
    {
        if(strlen($password)<12)throw new \InvalidArgumentException('Password must contain at least 12 characters.');$state=$this->throttle->check('password_token',$token,$ip,8,1800);if(!$state['allowed'])return false;$hash=hash('sha256',$token);$stmt=$this->db->prepare('SELECT * FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>?');$stmt->execute([$hash,Database::now()]);$record=$stmt->fetch();if(!$record){$this->throttle->record('password_token',$token,$ip,false);return false;}$now=Database::now();$claim=$this->db->prepare('UPDATE password_reset_tokens SET used_at=? WHERE id=? AND used_at IS NULL');$claim->execute([$now,$record['id']]);if($claim->rowCount()!==1){$this->throttle->record('password_token',$token,$ip,false);return false;}$this->users->setPassword((string)$record['user_id'],$password);$this->db->prepare('UPDATE password_reset_tokens SET used_at=? WHERE user_id=? AND used_at IS NULL')->execute([$now,$record['user_id']]);$this->sessions->revokeAll((string)$record['user_id']);$this->throttle->record('password_token',$token,$ip,true);return true;
    }

    private function expiryMinutes():int
    { return max(5,min(1440,(int)($this->settings->group('security')['password_reset_minutes']??30))); }
}
