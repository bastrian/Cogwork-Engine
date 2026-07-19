<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class PackActivityService
{
    public function __construct(private readonly PDO$db){}
    /** @param array<string,scalar|null> $context */ public function record(string$packId,string$action,string$result,string$summary,array$context=[],?string$actor=null):string{if(!preg_match('/^[a-z0-9_.-]{2,100}$/',$action)||!in_array($result,['success','failure','cancelled','info'],true))throw new \InvalidArgumentException('Invalid activity entry.');foreach(array_keys($context)as$key)if(preg_match('/password|secret|token|credential|private|content/i',(string)$key))unset($context[$key]);$id=Database::id();$this->db->prepare('INSERT INTO pack_activity (id,pack_id,actor_user_id,action,result,summary,context,created_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$id,$packId,$actor??($_SESSION['user_id']??$_SESSION['admin_id']??null),$action,$result,mb_substr($summary,0,500),json_encode($context,JSON_THROW_ON_ERROR),Database::now()]);return$id;}
    /** @param array{action?:string,result?:string,actor?:string,from?:string,to?:string} $filters @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int} */
    public function list(string$packId,int$page=1,int$perPage=50,array$filters=[]):array
    {
        $page=max(1,$page);$perPage=max(10,min(100,$perPage));$where='a.pack_id=?';$params=[$packId];
        if(!empty($filters['action'])){$where.=' AND a.action=?';$params[]=$filters['action'];}
        if(!empty($filters['result'])){$where.=' AND a.result=?';$params[]=$filters['result'];}
        if(!empty($filters['actor'])){$where.=" AND LOWER(COALESCE(u.display_name,'Deleted User')) LIKE ?";$params[]='%'.mb_strtolower($filters['actor']).'%';}
        if(!empty($filters['from'])){$where.=' AND a.created_at>=?';$params[]=$filters['from'].' 00:00:00';}
        if(!empty($filters['to'])){$where.=' AND a.created_at<=?';$params[]=$filters['to'].' 23:59:59';}
        $count=$this->db->prepare('SELECT COUNT(*) FROM pack_activity a LEFT JOIN users u ON u.id=a.actor_user_id WHERE '.$where);$count->execute($params);
        $stmt=$this->db->prepare("SELECT a.*,COALESCE(u.display_name,'Deleted User') actor_name FROM pack_activity a LEFT JOIN users u ON u.id=a.actor_user_id WHERE ".$where.' ORDER BY a.created_at DESC LIMIT '.$perPage.' OFFSET '.(($page-1)*$perPage));$stmt->execute($params);
        return['items'=>$stmt->fetchAll(),'total'=>(int)$count->fetchColumn(),'page'=>$page,'per_page'=>$perPage];
    }
}
