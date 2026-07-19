<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class UserDeletionService
{
    public const DELETED_USER_ID='00000000-0000-4000-8000-000000000001';
    public function __construct(private readonly PDO $db,private readonly string $applicationKey='') {}

    public function delete(string $userId,string $confirmation): void
    {
        if($confirmation!=='DELETE USER')throw new \InvalidArgumentException('Enter DELETE USER to confirm permanent deletion.');$stmt=$this->db->prepare('SELECT * FROM users WHERE id=?');$stmt->execute([$userId]);$user=$stmt->fetch();if(!$user)throw new HttpException(404,'User not found.');if($userId===self::DELETED_USER_ID)throw new \InvalidArgumentException('The Deleted User identity cannot be removed.');$owned=$this->db->prepare('SELECT COUNT(*) FROM pack_owners WHERE user_id=?');$owned->execute([$userId]);if((int)$owned->fetchColumn()>0)throw new \InvalidArgumentException('Transfer or delete every owned pack first.');if($user['role']==='admin'&&(int)$user['enabled']===1){$admins=(int)$this->db->query("SELECT COUNT(*) FROM users WHERE role='admin' AND enabled=1")->fetchColumn();if($admins<=1)throw new \InvalidArgumentException('The final enabled administrator cannot be deleted.');}
        $this->db->beginTransaction();try{$this->ensureDeletedUser();$this->replaceReferences($userId);$this->anonymizeJsonTable('audit_log','context',$userId,$user);$this->anonymizeJsonTable('security_events','context',$userId,$user);$this->anonymizeJsonTable('pack_activity','context',$userId,$user);$this->anonymizeActivitySummaries($user);foreach(['password_reset_tokens','email_verification_tokens','user_sessions','user_totp','recovery_codes','webauthn_credentials','auth_challenges','notifications','announcement_dismissals','pack_grants']as$table)$this->db->prepare("DELETE FROM {$table} WHERE user_id=?")->execute([$userId]);if($this->applicationKey!==''){foreach([$userId,(string)$user['username'],(string)($user['email']??'')]as$subject)if($subject!=='')$this->db->prepare('DELETE FROM auth_attempts WHERE subject_hash=?')->execute([hash_hmac('sha256',mb_strtolower(trim($subject)),$this->applicationKey)]);}$this->db->prepare('DELETE FROM admin_preferences WHERE admin_id=?')->execute([$userId]);$this->db->prepare('DELETE FROM admins WHERE id=?')->execute([$userId]);$this->db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);(new AuditService($this->db))->record('user.deleted',['deleted_user_id'=>self::DELETED_USER_ID]);$this->db->commit();}catch(\Throwable$e){$this->db->rollBack();throw$e;}
    }

    private function ensureDeletedUser(): void
    { $stmt=$this->db->prepare('SELECT COUNT(*) FROM users WHERE id=?');$stmt->execute([self::DELETED_USER_ID]);if((int)$stmt->fetchColumn())return;$now=Database::now();$this->db->prepare('INSERT INTO users (id,username,display_name,email,email_verified_at,password_hash,role,enabled,locale,tutorial_status,tutorial_step,session_version,last_login_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([self::DELETED_USER_ID,'deleted-user','Deleted User',null,null,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),'user',0,'en_US','completed',0,1,null,$now,$now]); }
    private function replaceReferences(string$userId): void
    { foreach([['job_actors','user_id'],['import_review_owners','user_id'],['pack_grants','granted_by'],['pack_activity','actor_user_id'],['announcements','created_by'],['security_events','user_id']]as[$table,$column])$this->db->prepare("UPDATE {$table} SET {$column}=? WHERE {$column}=?")->execute([self::DELETED_USER_ID,$userId]);$this->db->prepare('UPDATE announcements SET audience=?,archived_at=COALESCE(archived_at,?),updated_at=? WHERE audience=?')->execute(['user:'.self::DELETED_USER_ID,Database::now(),Database::now(),'user:'.$userId]); }
    /** @param array<string,mixed> $user */
    private function anonymizeJsonTable(string$table,string$column,string$userId,array$user): void
    { if(!in_array($table,['audit_log','security_events','pack_activity'],true))throw new \InvalidArgumentException('Unsupported anonymization table.');$rows=$this->db->query("SELECT id,{$column} FROM {$table}")->fetchAll();$update=$this->db->prepare("UPDATE {$table} SET {$column}=? WHERE id=?");foreach($rows as$row){$context=json_decode((string)$row[$column],true);if(!is_array($context))continue;$clean=$this->scrub($context,$userId,$user);$update->execute([json_encode($clean,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$row['id']]);} }
    /** @param array<string,mixed> $user */
    private function anonymizeActivitySummaries(array$user):void
    { $needles=array_values(array_filter([(string)($user['username']??''),(string)($user['display_name']??''),(string)($user['email']??'')]));if($needles===[])return;$rows=$this->db->query('SELECT id,summary FROM pack_activity')->fetchAll();$update=$this->db->prepare('UPDATE pack_activity SET summary=? WHERE id=?');foreach($rows as$row){$summary=str_ireplace($needles,'Deleted User',(string)$row['summary']);if(!hash_equals((string)$row['summary'],$summary))$update->execute([$summary,$row['id']]);} }
    /** @param array<string,mixed> $user */ private function scrub(mixed$value,string$userId,array$user,?string$key=null):mixed
    { if($key!==null&&preg_match('/(?:email|username|display_name|ip|address|user_agent|device)/i',$key))return'[deleted]';if(is_string($value)){if(hash_equals($value,$userId))return self::DELETED_USER_ID;foreach(['email','username','display_name']as$field)if(!empty($user[$field])&&hash_equals($value,(string)$user[$field]))return'[deleted]';return$value;}if(!is_array($value))return$value;$result=[];foreach($value as$childKey=>$child)$result[$childKey]=$this->scrub($child,$userId,$user,(string)$childKey);return$result; }
}
