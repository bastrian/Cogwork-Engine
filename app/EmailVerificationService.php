<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class EmailVerificationService
{
    public function __construct(private readonly PDO $db,private readonly UserService $users,private readonly MailService $mail,private readonly SystemSettings $settings) {}

    public function send(string $userId): void
    {
        $user=$this->users->find($userId);$email=(string)($user['email']??'');if($email==='')throw new \InvalidArgumentException('Add an email address first.');
        $base=rtrim((string)$this->settings->group('security')['canonical_url'],'/');if(!str_starts_with($base,'https://'))throw new \RuntimeException('Email verification requires a canonical HTTPS URL.');
        $token=bin2hex(random_bytes(32));$now=Database::now();$this->db->prepare('UPDATE email_verification_tokens SET used_at=? WHERE user_id=? AND used_at IS NULL')->execute([$now,$userId]);$this->db->prepare('INSERT INTO email_verification_tokens (id,user_id,token_hash,email,expires_at,used_at,created_at) VALUES (?,?,?,?,?,?,?)')->execute([Database::id(),$userId,hash('sha256',$token),$email,gmdate('c',time()+86400),null,$now]);
        $url=$base.'/index.php?route=email/verify&token='.rawurlencode($token);$this->mail->send($email,'Verify your Cogwork Engine email',"Confirm your email address using this one-time link:\n\n{$url}\n\nThe link expires in 24 hours.");
    }

    public function verify(string $token): bool
    {
        if(!preg_match('/^[a-f0-9]{64}$/',$token))return false;$stmt=$this->db->prepare('SELECT * FROM email_verification_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>?');$stmt->execute([hash('sha256',$token),Database::now()]);$row=$stmt->fetch();if(!$row)return false;$user=$this->users->find((string)$row['user_id']);if(!hash_equals((string)$row['email'],(string)$user['email']))return false;$now=Database::now();$this->db->beginTransaction();try{$claim=$this->db->prepare('UPDATE email_verification_tokens SET used_at=? WHERE id=? AND used_at IS NULL');$claim->execute([$now,$row['id']]);if($claim->rowCount()!==1){$this->db->rollBack();return false;}$this->db->prepare('UPDATE users SET email_verified_at=?,updated_at=? WHERE id=?')->execute([$now,$now,$row['user_id']]);$this->db->commit();return true;}catch(\Throwable$e){$this->db->rollBack();throw$e;}
    }
}
