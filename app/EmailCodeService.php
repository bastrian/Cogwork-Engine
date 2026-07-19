<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class EmailCodeService
{
    public function __construct(private readonly PDO $db,private readonly MailService $mail,private readonly AuthThrottle $throttle,private readonly string $key) {}

    public function send(string $userId,string $email,string $ip): void
    {
        $verified=$this->db->prepare('SELECT COUNT(*) FROM users WHERE id=? AND email=? AND email_verified_at IS NOT NULL AND enabled=1');$verified->execute([$userId,UserService::normalizeEmail($email)]);if((int)$verified->fetchColumn()!==1)throw new \RuntimeException('Email compatibility codes require a verified account address.');
        $state=$this->throttle->check('email_code_send',$userId,$ip,3,600);if(!$state['allowed'])throw new \RuntimeException('Try again later.');$this->throttle->record('email_code_send',$userId,$ip,false);$code=(string)random_int(100000,999999);$now=Database::now();$this->db->prepare("UPDATE auth_challenges SET used_at=? WHERE user_id=? AND purpose='email_code' AND used_at IS NULL")->execute([$now,$userId]);$payload=json_encode(['hash'=>hash_hmac('sha256',$code,$this->key),'email_hash'=>hash('sha256',mb_strtolower($email))],JSON_THROW_ON_ERROR);$this->db->prepare('INSERT INTO auth_challenges (id,user_id,purpose,challenge_hash,payload,expires_at,used_at,created_at) VALUES (?,?,?,?,?,?,?,?)')->execute([Database::id(),$userId,'email_code',hash('sha256',random_bytes(32)),$payload,gmdate('c',time()+600),null,$now]);$this->mail->send($email,'Your Cogwork Engine sign-in code',"Your one-time code is {$code}.\n\nIt expires in 10 minutes. Never share this code.");
    }

    public function verify(string $userId,string $code,string $ip): bool
    {
        $state=$this->throttle->check('email_code_verify',$userId,$ip,5,600);if(!$state['allowed'])return false;$stmt=$this->db->prepare("SELECT * FROM auth_challenges WHERE user_id=? AND purpose='email_code' AND used_at IS NULL AND expires_at>? ORDER BY created_at DESC LIMIT 1");$stmt->execute([$userId,Database::now()]);$row=$stmt->fetch();$payload=$row?json_decode((string)$row['payload'],true):null;$valid=$row&&is_array($payload)&&preg_match('/^\d{6}$/',$code)&&hash_equals((string)$payload['hash'],hash_hmac('sha256',$code,$this->key));if($valid){$claim=$this->db->prepare('UPDATE auth_challenges SET used_at=? WHERE id=? AND used_at IS NULL');$claim->execute([Database::now(),$row['id']]);$valid=$claim->rowCount()===1;}$this->throttle->record('email_code_verify',$userId,$ip,(bool)$valid);return(bool)$valid;
    }
}
