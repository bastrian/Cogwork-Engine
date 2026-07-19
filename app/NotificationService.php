<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class NotificationService
{
    private const SEVERITIES=['info','success','warning','critical'];
    public const CATEGORIES=['security','jobs','builds','migrations','maintenance','announcements','permissions','backups','updates'];
    private const MANDATORY=['security'];

    public function __construct(private readonly PDO $db) {}

    public function send(string $userId,string $category,string $severity,string $title,string $message,string $targetUrl='',bool$emailDelivery=true): string
    {
        if(!(new SystemSettings($this->db))->feature('notifications'))return'';
        if(!in_array($severity,self::SEVERITIES,true)||!preg_match('/^[a-z0-9_.-]{2,50}$/',$category))throw new \InvalidArgumentException('Invalid notification.');
        if($targetUrl!==''&&!str_starts_with($targetUrl,'/'))throw new \InvalidArgumentException('Notification targets must be local paths.');
        if(!$this->preference($userId,$category)['in_app']&&!in_array($category,self::MANDATORY,true))return'';
        $duplicate=$this->db->prepare('SELECT id FROM notifications WHERE user_id=? AND category=? AND title=? AND message=? AND target_url=? AND created_at>=? ORDER BY created_at DESC LIMIT 1');$duplicate->execute([$userId,$category,mb_substr($title,0,200),$message,$targetUrl,gmdate('c',time()-600)]);if($existing=$duplicate->fetchColumn())return(string)$existing;
        $id=Database::id();$stmt=$this->db->prepare('INSERT INTO notifications (id,user_id,category,severity,title,message,target_url,created_at) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$id,$userId,$category,$severity,mb_substr($title,0,200),$message,$targetUrl,Database::now()]);if($emailDelivery&&$this->preference($userId,$category)['email'])$this->email($userId,$category,$title,$message);return$id;
    }

    /** @param list<string>|null $userIds */
    public function broadcast(string$category,string$severity,string$title,string$message,string$targetUrl='',?array$userIds=null):int
    { if(!(new SystemSettings($this->db))->feature('notifications'))return 0;if($userIds===null)$userIds=array_map('strval',$this->db->query('SELECT id FROM users WHERE enabled=1 ORDER BY id LIMIT 1000')->fetchAll(PDO::FETCH_COLUMN));$count=0;foreach(array_values(array_unique($userIds))as$userId){$enabled=$this->db->prepare('SELECT COUNT(*) FROM users WHERE id=? AND enabled=1');$enabled->execute([$userId]);if((int)$enabled->fetchColumn()!==1)continue;if($this->send($userId,$category,$severity,$title,$message,$targetUrl,false)!=='')$count++;}return$count;}

    /** @return list<array<string,mixed>> */
    public function forUser(string $userId,int $limit=50,bool $includeArchived=false): array
    {
        $sql='SELECT * FROM notifications WHERE user_id=?'.($includeArchived?'':' AND archived_at IS NULL').' ORDER BY created_at DESC LIMIT '.max(1,min(200,$limit));
        $stmt=$this->db->prepare($sql);$stmt->execute([$userId]);$items=$stmt->fetchAll();$userStmt=$this->db->prepare('SELECT * FROM users WHERE id=? AND enabled=1');$userStmt->execute([$userId]);$user=$userStmt->fetch();if(!$user)return[];$authorization=new Authorization($this->db,$user);foreach($items as&$item)if(!$this->targetAllowed((string)$item['target_url'],$authorization))$item['target_url']='';unset($item);return$items;
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int} */
    public function page(string$userId,int$page=1,int$perPage=25,bool$includeArchived=false):array
    { $page=max(1,$page);$perPage=max(5,min(100,$perPage));$where='user_id=?'.($includeArchived?' AND archived_at IS NOT NULL':' AND archived_at IS NULL');$count=$this->db->prepare('SELECT COUNT(*) FROM notifications WHERE '.$where);$count->execute([$userId]);$total=(int)$count->fetchColumn();$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);$sql='SELECT * FROM notifications WHERE '.$where.' ORDER BY created_at DESC LIMIT '.$perPage.' OFFSET '.(($page-1)*$perPage);$stmt=$this->db->prepare($sql);$stmt->execute([$userId]);$items=$stmt->fetchAll();$userStmt=$this->db->prepare('SELECT * FROM users WHERE id=? AND enabled=1');$userStmt->execute([$userId]);$user=$userStmt->fetch();if(!$user)$items=[];else{$authorization=new Authorization($this->db,$user);foreach($items as&$item)if(!$this->targetAllowed((string)$item['target_url'],$authorization))$item['target_url']='';unset($item);}return compact('items','total','page','pages'); }

    /** @return array{in_app:bool,email:bool,mandatory:bool} */
    public function preference(string$userId,string$category):array
    { $mandatory=in_array($category,self::MANDATORY,true);$stmt=$this->db->prepare('SELECT in_app,email FROM notification_preferences WHERE user_id=? AND category=?');$stmt->execute([$userId,$category]);$row=$stmt->fetch();$defaults=(new SystemSettings($this->db))->group('notification_defaults');$inAppDefaults=is_array($defaults['in_app']??null)?$defaults['in_app']:[];$emailDefaults=is_array($defaults['email']??null)?$defaults['email']:[];return['in_app'=>$mandatory||($row?(bool)$row['in_app']:(bool)($inAppDefaults[$category]??true)),'email'=>$row?(bool)$row['email']:(bool)($emailDefaults[$category]??$mandatory),'mandatory'=>$mandatory]; }

    public function setPreferences(string$userId,array$inApp,array$email):void
    { foreach(self::CATEGORIES as$category){$mandatory=in_array($category,self::MANDATORY,true);$this->db->prepare('DELETE FROM notification_preferences WHERE user_id=? AND category=?')->execute([$userId,$category]);$this->db->prepare('INSERT INTO notification_preferences (user_id,category,in_app,email,updated_at) VALUES (?,?,?,?,?)')->execute([$userId,$category,$mandatory||in_array($category,$inApp,true)?1:0,$mandatory||in_array($category,$email,true)?1:0,Database::now()]);} }

    public function unreadCount(string $userId): int
    { $stmt=$this->db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL AND archived_at IS NULL');$stmt->execute([$userId]);return(int)$stmt->fetchColumn(); }

    public function markRead(string $id,string $userId): void
    { $stmt=$this->db->prepare('UPDATE notifications SET read_at=? WHERE id=? AND user_id=? AND read_at IS NULL');$stmt->execute([Database::now(),$id,$userId]); }
    public function markAllRead(string$userId):int{$stmt=$this->db->prepare('UPDATE notifications SET read_at=? WHERE user_id=? AND read_at IS NULL AND archived_at IS NULL');$stmt->execute([Database::now(),$userId]);return$stmt->rowCount();}
    public function acknowledge(string$id,string$userId):void{$this->db->prepare('UPDATE notifications SET acknowledged_at=?,read_at=COALESCE(read_at,?) WHERE id=? AND user_id=?')->execute([Database::now(),Database::now(),$id,$userId]);}
    public function archive(string$id,string$userId):void{$this->db->prepare('UPDATE notifications SET archived_at=?,read_at=COALESCE(read_at,?) WHERE id=? AND user_id=?')->execute([Database::now(),Database::now(),$id,$userId]);}

    private function targetAllowed(string$url,Authorization$authorization):bool
    {
        if($url==='')return true;$parts=parse_url($url);if(!is_array($parts)||isset($parts['scheme'])||isset($parts['host'])||!str_starts_with((string)($parts['path']??''),'/'))return false;parse_str((string)($parts['query']??''),$query);$route=trim((string)($query['route']??''),'/');if($route==='')return true;
        if(str_starts_with($route,'admin'))return$authorization->admin();
        if(in_array($route,['account','notifications','help','tutorial','settings'],true))return true;
        $packId=(string)($query['id']??'');if(str_starts_with($route,'packs/')&&$packId!=='')return$authorization->can($packId,'view');
        if(str_starts_with($route,'jobs/')&&isset($query['id'])){$stmt=$this->db->prepare('SELECT pack_id FROM jobs WHERE id=?');$stmt->execute([(string)$query['id']]);$pack=$stmt->fetchColumn();return is_string($pack)&&$pack!==''&&$authorization->can($pack,'view');}
        if($route==='packages/download'&&isset($query['id'])){$stmt=$this->db->prepare('SELECT pack_id FROM packages WHERE id=?');$stmt->execute([(string)$query['id']]);$pack=$stmt->fetchColumn();return is_string($pack)&&$pack!==''&&$authorization->can($pack,'build');}
        return false;
    }

    private function email(string$userId,string$category,string$title,string$message):void
    { try{if(!Config::installed())return;$settings=new SystemSettings($this->db);$mail=$settings->group('mail');if((string)$mail['from_address']==='')return;$rateKey='notification.mail.'.hash('sha256',$userId.'\0'.$category);$last=(int)$settings->get($rateKey,0);if($last>time()-600)return;$maintenance=(new MaintenanceService($settings))->state();$mail['paused']=!empty($maintenance['active'])&&!empty($maintenance['pause_mail']);$mail['password']=(new SecretStore())->get('smtp.password');$stmt=$this->db->prepare('SELECT email FROM users WHERE id=? AND enabled=1');$stmt->execute([$userId]);$email=$stmt->fetchColumn();if(!is_string($email)||$email==='')return;(new MailService($mail))->send($email,$title,$message."\n\nOpen Cogwork Engine to review the durable notification. No passwords, codes, tokens, or private file contents are included.");$settings->set($rateKey,time());}catch(\Throwable){/* The durable in-application notification is the delivery record. */}}
}
