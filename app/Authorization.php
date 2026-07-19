<?php
declare(strict_types=1);
namespace Modright;
use PDO;

final class Authorization
{
    public const CAPABILITIES=['view','edit_metadata','manage_mods','synchronize','update','migrate','build','server_settings','share','delete'];
    public const PRESETS=['viewer'=>['view'],'contributor'=>['view','edit_metadata','manage_mods','synchronize'],'maintainer'=>['view','edit_metadata','manage_mods','synchronize','update','migrate','build','server_settings'],'custom'=>[]];
    public function __construct(private readonly PDO $db,private readonly array $user) {}
    public function user(): array{return$this->user;} public function admin(): bool{return$this->user['role']==='admin';}
    public function can(string $packId,string $capability): bool
    { if($this->admin())return true;$stmt=$this->db->prepare('SELECT COUNT(*) FROM pack_owners WHERE pack_id=? AND user_id=?');$stmt->execute([$packId,$this->user['id']]);if((int)$stmt->fetchColumn()>0)return true;$stmt=$this->db->prepare('SELECT permissions_json FROM pack_grants WHERE pack_id=? AND user_id=?');$stmt->execute([$packId,$this->user['id']]);$json=$stmt->fetchColumn();$permissions=$json?json_decode((string)$json,true):[];return is_array($permissions)&&in_array($capability,$permissions,true); }
    public function requirePack(string $packId,string $capability='view'): void { if(!$this->can($packId,$capability))throw new HttpException(403,'Access denied.'); }
    /** @return list<array<string,mixed>> */
    public function packs(): array
    { if($this->admin())return$this->db->query('SELECT * FROM packs ORDER BY updated_at DESC')->fetchAll();$stmt=$this->db->prepare('SELECT DISTINCT p.* FROM packs p LEFT JOIN pack_owners o ON o.pack_id=p.id LEFT JOIN pack_grants g ON g.pack_id=p.id WHERE o.user_id=? OR g.user_id=? ORDER BY p.updated_at DESC');$stmt->execute([$this->user['id'],$this->user['id']]);return$stmt->fetchAll(); }
    public function assignOwner(string $packId,string $userId): void
    { $target=$this->db->prepare('SELECT enabled FROM users WHERE id=?');$target->execute([$userId]);if((int)$target->fetchColumn()!==1)throw new \InvalidArgumentException('Ownership can only be transferred to an enabled user.');$this->db->prepare('DELETE FROM pack_owners WHERE pack_id=?')->execute([$packId]);$this->db->prepare('INSERT INTO pack_owners (pack_id,user_id,created_at) VALUES (?,?,?)')->execute([$packId,$userId,Database::now()]);$this->audit('pack.owner_transferred',['pack_id'=>$packId,'new_owner'=>$userId]); }
    public function owner(string $packId): string
    { $stmt=$this->db->prepare('SELECT user_id FROM pack_owners WHERE pack_id=?');$stmt->execute([$packId]);return(string)$stmt->fetchColumn(); }
    /** @return list<array<string,mixed>> */
    public function grants(string $packId): array
    { $stmt=$this->db->prepare('SELECT g.*,u.username,u.display_name FROM pack_grants g JOIN users u ON u.id=g.user_id WHERE g.pack_id=? ORDER BY u.username');$stmt->execute([$packId]);return$stmt->fetchAll(); }
    /** @param list<string> $permissions */
    public function grant(string $packId,string $userId,string $preset,array $permissions): void
    { $this->requirePack($packId,'share');$target=$this->db->prepare('SELECT enabled FROM users WHERE id=?');$target->execute([$userId]);if((int)$target->fetchColumn()!==1)throw new \InvalidArgumentException('Access can only be granted to an enabled user.');if($userId===$this->owner($packId))throw new \InvalidArgumentException('The owner already has full access.');if($preset!=='custom')$permissions=self::PRESETS[$preset]??[];$permissions=array_values(array_intersect(self::CAPABILITIES,$permissions));foreach($permissions as$permission)if(!$this->can($packId,$permission))throw new HttpException(403,'You cannot grant a permission you do not hold.');$this->db->prepare('DELETE FROM pack_grants WHERE pack_id=? AND user_id=?')->execute([$packId,$userId]);$now=Database::now();$stmt=$this->db->prepare('INSERT INTO pack_grants (pack_id,user_id,preset,permissions_json,granted_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?)');$stmt->execute([$packId,$userId,$preset,json_encode($permissions,JSON_THROW_ON_ERROR),$this->user['id'],$now,$now]);$this->audit('pack.grant_changed',['pack_id'=>$packId,'user_id'=>$userId,'preset'=>$preset,'permissions'=>$permissions]); }
    public function revoke(string $packId,string $userId): void { $this->requirePack($packId,'share');$this->db->prepare('DELETE FROM pack_grants WHERE pack_id=? AND user_id=?')->execute([$packId,$userId]);$this->audit('pack.grant_revoked',['pack_id'=>$packId,'user_id'=>$userId]); }
    private function audit(string $action,array $context): void { $context['actor_user_id']=$this->user['id'];$stmt=$this->db->prepare('INSERT INTO audit_log (id,action,context,created_at) VALUES (?,?,?,?)');$stmt->execute([Database::id(),$action,json_encode($context,JSON_THROW_ON_ERROR),Database::now()]); }
}
