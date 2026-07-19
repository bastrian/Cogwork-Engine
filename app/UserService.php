<?php
declare(strict_types=1);
namespace Modright;
use PDO;

final class UserService
{
    public function __construct(private readonly PDO $db) {}
    /** @return array<string,mixed>|null */
    public function authenticate(string $username,string $password): ?array
    { $stmt=$this->db->prepare('SELECT * FROM users WHERE username=?');$stmt->execute([trim($username)]);$user=$stmt->fetch();return$user&&(int)$user['enabled']===1&&password_verify($password,$user['password_hash'])?$user:null; }
    /** @return array<string,mixed> */
    public function find(string $id): array
    { $stmt=$this->db->prepare('SELECT * FROM users WHERE id=?');$stmt->execute([$id]);$user=$stmt->fetch();if(!$user)throw new HttpException(404,'User not found.');return$user; }
    /** @return list<array<string,mixed>> */ public function all(): array { return$this->db->query('SELECT * FROM users ORDER BY username')->fetchAll(); }
    public function create(string $username,string $displayName,string $password,string $role,string $locale): string
    { if(!preg_match('/^[A-Za-z0-9_.-]{3,50}$/',$username)||strlen($password)<12||!in_array($role,['admin','user'],true))throw new \InvalidArgumentException('Enter a valid username, role, and password of at least 12 characters.');$id=Database::id();$now=Database::now();$stmt=$this->db->prepare('INSERT INTO users (id,username,display_name,password_hash,role,enabled,locale,tutorial_status,tutorial_step,session_version,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');$stmt->execute([$id,$username,$displayName?:$username,password_hash($password,PASSWORD_DEFAULT),$role,1,Translator::normalize($locale),'not_started',0,1,$now,$now]);$this->audit('user.created',['user_id'=>$id,'role'=>$role]);return$id; }
    public function update(string $id,string $displayName,string $role,bool $enabled,string $locale,?string $password=null): void
    { $user=$this->find($id);if(!in_array($role,['admin','user'],true))throw new \InvalidArgumentException('Invalid role.');if($user['role']==='admin'&&(int)$user['enabled']===1&&($role!=='admin'||!$enabled)){if((int)$this->db->query("SELECT COUNT(*) FROM users WHERE role='admin' AND enabled=1")->fetchColumn()<=1)throw new \InvalidArgumentException('At least one enabled administrator is required.');}$hash=$user['password_hash'];if($password!==null&&$password!==''){if(strlen($password)<12)throw new \InvalidArgumentException('New password must contain at least 12 characters.');$hash=password_hash($password,PASSWORD_DEFAULT);}$sessionVersion=(int)$user['session_version']+((int)$user['enabled']!==(int)$enabled||$user['role']!==$role||$hash!==$user['password_hash']?1:0);$stmt=$this->db->prepare('UPDATE users SET display_name=?,role=?,enabled=?,locale=?,password_hash=?,session_version=?,updated_at=? WHERE id=?');$stmt->execute([$displayName,$role,$enabled?1:0,Translator::normalize($locale),$hash,$sessionVersion,Database::now(),$id]);$this->audit('user.updated',['user_id'=>$id,'role'=>$role,'enabled'=>$enabled]); }
    public function preferences(string $id,string $displayName,string $locale): void
    { $stmt=$this->db->prepare('UPDATE users SET display_name=?,locale=?,updated_at=? WHERE id=?');$stmt->execute([$displayName,Translator::normalize($locale),Database::now(),$id]); }
    public function tutorial(string $id,string $status,int $step): void
    { if(!in_array($status,['not_started','in_progress','skipped','completed'],true))throw new \InvalidArgumentException('Invalid tutorial state.');$stmt=$this->db->prepare('UPDATE users SET tutorial_status=?,tutorial_step=?,updated_at=? WHERE id=?');$stmt->execute([$status,max(0,min(20,$step)),Database::now(),$id]); }
    private function audit(string $action,array $context): void { $context['actor_user_id']=$_SESSION['user_id']??$_SESSION['admin_id']??null;$stmt=$this->db->prepare('INSERT INTO audit_log (id,action,context,created_at) VALUES (?,?,?,?)');$stmt->execute([Database::id(),$action,json_encode($context,JSON_THROW_ON_ERROR),Database::now()]); }
}
