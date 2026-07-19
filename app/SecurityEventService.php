<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class SecurityEventService
{
    public function __construct(private readonly PDO $db,private readonly ?MailService$mail=null,private readonly ?SystemSettings$settings=null) {}

    /** @param array<string,scalar|null> $context */
    public function record(?string $userId,string $type,string $severity='info',array $context=[]): string
    {
        if(!preg_match('/^[a-z][a-z0-9_.-]{1,99}$/',$type))throw new \InvalidArgumentException('Invalid security event type.');
        if(!in_array($severity,['info','warning','critical'],true))throw new \InvalidArgumentException('Invalid severity.');
        foreach(array_keys($context) as $key)if(preg_match('/password|token|secret|code|credential|ip|email/i',(string)$key))unset($context[$key]);
        $id=Database::id();$this->db->prepare('INSERT INTO security_events (id,user_id,event_type,severity,context,created_at) VALUES (?,?,?,?,?,?)')->execute([$id,$userId,$type,$severity,json_encode($context,JSON_THROW_ON_ERROR),Database::now()]);if($userId!==null&&$type!=='session.created'&&in_array($severity,['warning','critical'],true))$this->notify($userId,$type,$severity);return$id;
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit=100): array
    { $limit=max(1,min(500,$limit));return$this->db->query('SELECT * FROM security_events ORDER BY created_at DESC LIMIT '.$limit)->fetchAll(); }

    private function notify(string$userId,string$type,string$severity):void
    { $title=$severity==='critical'?'Critical account-security event':'Account-security notice';$message='Security event: '.str_replace(['.','_'],' ',$type).'. Review your active sessions and authentication factors.';$notifications=new NotificationService($this->db);$notifications->send($userId,'security',$severity,$title,$message,Application::url('account').'#security',false);if($this->mail===null||$this->settings===null||!$notifications->preference($userId,'security')['email'])return;$rateKey='security.mail.'.hash('sha256',$userId);$last=(int)$this->settings->get($rateKey,0);if($last>time()-3600)return;$stmt=$this->db->prepare('SELECT email FROM users WHERE id=? AND enabled=1');$stmt->execute([$userId]);$email=$stmt->fetchColumn();if(!is_string($email)||$email==='')return;try{$this->mail->send($email,$title,$message."\n\nSign in to Cogwork Engine to review the event. No passwords, codes, tokens, or network addresses are included in this email.");$this->settings->set($rateKey,time());}catch(\Throwable){/* Durable in-application notice remains available when mail fails. */} }
}
