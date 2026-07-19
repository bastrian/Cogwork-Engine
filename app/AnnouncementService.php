<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class AnnouncementService
{
    public function __construct(private readonly PDO $db) {}

    /** @return list<array<string,mixed>> */
    public function activeFor(?array $user,string $locale='en_US'): array
    {
        $this->reconcileLifecycle();$now=Database::now();$stmt=$this->db->prepare('SELECT a.* FROM announcements a LEFT JOIN announcement_dismissals d ON d.announcement_id=a.id AND d.user_id=? WHERE a.archived_at IS NULL AND (a.starts_at IS NULL OR a.starts_at<=?) AND (a.ends_at IS NULL OR a.ends_at>?) AND (d.user_id IS NULL OR a.dismissible=0) ORDER BY a.created_at DESC');$stmt->execute([(string)($user['id']??''),$now,$now]);
        $items=[];foreach($stmt->fetchAll()as$item){if(!$this->audienceMatches((string)$item['audience'],$user))continue;$de=$locale==='de_DE';$item['title']=$de&&$item['title_de']!==''?$item['title_de']:$item['title_en'];$item['message']=$de&&$item['message_de']!==''?$item['message_de']:$item['message_en'];$items[]=$item;}return$items;
    }

    public function reconcileLifecycle(int $limit=100): int
    {
        $now=Database::now();$limit=max(1,min(500,$limit));$count=0;$audit=new AuditService($this->db);
        $activate=$this->db->prepare('SELECT id FROM announcements WHERE archived_at IS NULL AND activated_at IS NULL AND (starts_at IS NULL OR starts_at<=?) AND (ends_at IS NULL OR ends_at>?) ORDER BY created_at LIMIT '.$limit);$activate->execute([$now,$now]);
        $markActive=$this->db->prepare('UPDATE announcements SET activated_at=? WHERE id=? AND activated_at IS NULL');
        foreach($activate->fetchAll()as$item){$markActive->execute([$now,$item['id']]);if($markActive->rowCount()===1){$audit->record('announcement.activated',['announcement_id'=>$item['id']]);$count++;}}
        $expire=$this->db->prepare('SELECT id FROM announcements WHERE expired_at IS NULL AND ends_at IS NOT NULL AND ends_at<=? ORDER BY ends_at LIMIT '.$limit);$expire->execute([$now]);
        $markExpired=$this->db->prepare('UPDATE announcements SET expired_at=? WHERE id=? AND expired_at IS NULL');
        foreach($expire->fetchAll()as$item){$markExpired->execute([$now,$item['id']]);if($markExpired->rowCount()===1){$audit->record('announcement.expired',['announcement_id'=>$item['id']]);$count++;}}
        return$count;
    }

    public function dismiss(string $id,string $userId): void
    {
        $stmt=$this->db->prepare('SELECT dismissible,severity FROM announcements WHERE id=?');$stmt->execute([$id]);$item=$stmt->fetch();if(!$item||(int)$item['dismissible']!==1||in_array($item['severity'],['critical','maintenance'],true))throw new \InvalidArgumentException('This announcement cannot be dismissed.');
        $this->db->prepare('DELETE FROM announcement_dismissals WHERE announcement_id=? AND user_id=?')->execute([$id,$userId]);$this->db->prepare('INSERT INTO announcement_dismissals (announcement_id,user_id,dismissed_at) VALUES (?,?,?)')->execute([$id,$userId,Database::now()]);
    }

    /** @param array<string,mixed> $data */ public function save(array$data,?string$id=null):string
    { $severity=(string)($data['severity']??'info');$audience=(string)($data['audience']??'everyone');if(!in_array($severity,['info','success','warning','maintenance','critical'],true)||!preg_match('/^(everyone|authenticated|administrators|pack_owners|user:[a-f0-9-]{36})$/',$audience))throw new \InvalidArgumentException('Invalid announcement severity or audience.');if(str_starts_with($audience,'user:')){$targetUser=$this->db->prepare('SELECT COUNT(*) FROM users WHERE id=? AND enabled=1');$targetUser->execute([substr($audience,5)]);if((int)$targetUser->fetchColumn()!==1)throw new \InvalidArgumentException('The selected announcement recipient is unavailable.');}$target=trim((string)($data['target_url']??''));if(!$this->safeTarget($target))throw new \InvalidArgumentException('Announcement link must be a safe local path or absolute HTTPS URL.');$starts=$this->schedule($data['starts_at']??null);$ends=$this->schedule($data['ends_at']??null);if($starts!==null&&$ends!==null&&strtotime($ends)<=strtotime($starts))throw new \InvalidArgumentException('Announcement end must be after its start.');$now=Database::now();$base=[$severity,$audience,trim((string)($data['title_en']??'')),trim((string)($data['message_en']??'')),trim((string)($data['title_de']??'')),trim((string)($data['message_de']??'')),$target,!empty($data['dismissible'])?1:0,$starts,$ends,$data['created_by']??null];if($base[2]===''||$base[3]==='')throw new \InvalidArgumentException('English title and message are required.');if($id===null){$id=Database::id();$this->db->prepare('INSERT INTO announcements (id,severity,audience,title_en,message_en,title_de,message_de,target_url,dismissible,starts_at,ends_at,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge([$id],$base,[$now,$now]));}else{$this->db->prepare('UPDATE announcements SET severity=?,audience=?,title_en=?,message_en=?,title_de=?,message_de=?,target_url=?,dismissible=?,starts_at=?,ends_at=?,created_by=?,activated_at=NULL,expired_at=NULL,updated_at=? WHERE id=?')->execute(array_merge($base,[$now,$id]));}return$id; }
    /** @return list<array<string,mixed>> */ public function all():array{return$this->db->query('SELECT * FROM announcements ORDER BY created_at DESC')->fetchAll();}
    /** @return array<string,mixed> */ public function find(string$id):array{$stmt=$this->db->prepare('SELECT * FROM announcements WHERE id=?');$stmt->execute([$id]);$item=$stmt->fetch();if(!$item)throw new \InvalidArgumentException('Announcement not found.');return$item;}
    public function archive(string$id):void{$this->db->prepare('UPDATE announcements SET archived_at=?,updated_at=? WHERE id=?')->execute([Database::now(),Database::now(),$id]);}
    public function delete(string$id):void{$this->db->prepare('DELETE FROM announcements WHERE id=?')->execute([$id]);}

    private function audienceMatches(string $audience,?array $user): bool
    { return match($audience){'everyone'=>true,'authenticated'=>$user!==null,'administrators'=>$user!==null&&$user['role']==='admin','pack_owners'=>$user!==null&&$this->ownsPack((string)$user['id']),default=>$user!==null&&str_starts_with($audience,'user:')&&substr($audience,5)===$user['id']}; }

    private function ownsPack(string $userId): bool
    { $stmt=$this->db->prepare('SELECT COUNT(*) FROM pack_owners WHERE user_id=?');$stmt->execute([$userId]);return(int)$stmt->fetchColumn()>0; }
    private function safeTarget(string$url):bool
    { if($url==='')return true;if(preg_match('/[\x00-\x1F\x7F]/',$url))return false;if(str_starts_with($url,'/')&&!str_starts_with($url,'//')){$parts=parse_url($url);return is_array($parts)&&!isset($parts['scheme'])&&!isset($parts['host']);}$parts=parse_url($url);return is_array($parts)&&($parts['scheme']??'')==='https'&&!empty($parts['host'])&&!isset($parts['user'])&&!isset($parts['pass']); }
    private function schedule(mixed$value):?string
    { if($value===null||trim((string)$value)==='')return null;$value=trim((string)$value);if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})$/',$value))throw new \InvalidArgumentException('Invalid announcement schedule.');try{$date=new \DateTimeImmutable($value);}catch(\Throwable){throw new \InvalidArgumentException('Invalid announcement schedule.');}return$date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP'); }
}
