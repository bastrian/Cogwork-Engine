<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class EmergencyRecoveryService
{
    public const ACTIONS=['maintenance','public_url','mail','logout','two_factor'];

    public function __construct(private readonly PDO$db,private readonly SystemSettings$settings,private readonly SecretStore$secrets){}

    public function apply(string$adminId,string$password,string$confirmation,string$action):void
    {
        if($confirmation!=='RECOVER SYSTEM'||!in_array($action,self::ACTIONS,true))throw new \InvalidArgumentException('Enter RECOVER SYSTEM and choose a supported recovery action.');
        $stmt=$this->db->prepare('SELECT password_hash,role,enabled FROM users WHERE id=?');$stmt->execute([$adminId]);$admin=$stmt->fetch();if(!$admin||$admin['role']!=='admin'||(int)$admin['enabled']!==1||!password_verify($password,(string)$admin['password_hash']))throw new HttpException(403,'Administrator password confirmation failed.');
        $this->db->beginTransaction();try{
            if($action==='maintenance'){$maintenance=$this->settings->group('maintenance');$maintenance['enabled']=false;$maintenance['starts_at']=null;$maintenance['ends_at']=null;$this->settings->setGroup('maintenance',$maintenance);}
            elseif($action==='public_url'){$security=$this->settings->group('security');$security['canonical_url']='';$security['trusted_proxies']=[];$this->settings->setGroup('security',$security);}
            elseif($action==='mail'){$mail=$this->settings->group('mail');$mail['transport']='mail';$mail['host']='';$mail['username']='';$mail['from_address']='';$this->settings->setGroup('mail',$mail);$features=$this->settings->group('features');$features['password_recovery']=false;$this->settings->setGroup('features',$features);}
            elseif($action==='logout'){$security=$this->settings->group('security');$security['logout_redirect']='';$this->settings->setGroup('security',$security);}
            else{$security=$this->settings->group('security');$security['two_factor_policy']='disabled';$this->settings->setGroup('security',$security);$features=$this->settings->group('features');$features['two_factor']=false;$features['passkeys']=false;$this->settings->setGroup('features',$features);}
            (new AuditService($this->db))->record('emergency_recovery.applied',['action'=>$action],$adminId);
            $now=Database::now();$this->db->prepare('UPDATE user_sessions SET revoked_at=? WHERE revoked_at IS NULL')->execute([$now]);$this->db->prepare('UPDATE users SET session_version=session_version+1,updated_at=? WHERE enabled=1')->execute([$now]);
            $this->db->commit();
        }catch(\Throwable$e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
        // The database action disables recovery mail before the protected
        // credential is removed. If this filesystem write fails, the old
        // secret remains inert and the audited/session-invalidating recovery
        // still takes effect instead of being falsely rolled back.
        if($action==='mail')$this->secrets->update(['smtp.password'=>null]);
    }
}
