<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class AuditService
{
    public function __construct(private readonly PDO $db) {}

    /** @param array<string,mixed> $context */
    public function record(string $action,array $context=[],?string $actorUserId=null): string
    {
        if(!preg_match('/^[a-z0-9_.-]{2,100}$/',$action))throw new \InvalidArgumentException('Invalid audit action.');
        $context['actor_user_id']=$actorUserId??($_SESSION['user_id']??$_SESSION['admin_id']??null);
        $id=Database::id();$stmt=$this->db->prepare('INSERT INTO audit_log (id,action,context,created_at) VALUES (?,?,?,?)');
        $stmt->execute([$id,$action,json_encode($this->redact($context),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),Database::now()]);return$id;
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit=100): array
    {
        $limit=max(1,min(500,$limit));return$this->db->query('SELECT * FROM audit_log ORDER BY created_at DESC LIMIT '.$limit)->fetchAll();
    }

    /** @param array{action?:string,account?:string,pack?:string,from?:string,to?:string,source?:string} $filters @return list<array<string,mixed>> */
    public function search(array$filters=[],int$limit=200):array
    {
        $limit=max(1,min(500,$limit));$source=(string)($filters['source']??'all');$items=[];if($source!=='security')$items=$this->query('audit_log','action',$filters,$limit,'audit');if($source!=='audit')$items=array_merge($items,$this->query('security_events','event_type',$filters,$limit,'security'));usort($items,fn($a,$b)=>strcmp((string)$b['created_at'],(string)$a['created_at']));return array_slice($items,0,$limit);
    }

    /** @param array<string,string> $filters @return list<array<string,mixed>> */
    private function query(string$table,string$actionColumn,array$filters,int$limit,string$source):array
    { $where=['1=1'];$params=[];if(($filters['action']??'')!==''){$where[]="{$actionColumn} LIKE ?";$params[]='%'.$filters['action'].'%';}if(($filters['account']??'')!==''){if($table==='security_events'){$where[]='user_id=?';$params[]=$filters['account'];}else{$where[]='context LIKE ?';$params[]='%"actor_user_id":"'.$filters['account'].'"%';}}if(($filters['pack']??'')!==''){$where[]='context LIKE ?';$params[]='%"pack_id":"'.$filters['pack'].'"%';}if(($filters['from']??'')!==''){$where[]='created_at>=?';$params[]=$filters['from'].'T00:00:00+00:00';}if(($filters['to']??'')!==''){$where[]='created_at<=?';$params[]=$filters['to'].'T23:59:59+00:00';}$select=$table==='security_events'?"id,{$actionColumn} action,context,created_at":"id,{$actionColumn} action,context,created_at";$stmt=$this->db->prepare("SELECT {$select} FROM {$table} WHERE ".implode(' AND ',$where)." ORDER BY created_at DESC LIMIT {$limit}");$stmt->execute($params);$rows=$stmt->fetchAll();foreach($rows as&$row)$row['source']=$source;unset($row);return$rows;}

    private function redact(mixed $value,?string $key=null): mixed
    {
        if($key!==null&&preg_match('/(?:password|secret|token|code|credential|authorization|cookie)/i',$key))return'[redacted]';
        if(!is_array($value))return$value;
        $result=[];foreach($value as$childKey=>$child)$result[$childKey]=$this->redact($child,(string)$childKey);return$result;
    }
}
