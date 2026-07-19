<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class OfflineCatalog
{
    public function __construct(private readonly PDO $db) {}

    /** @param array<string,mixed> $index @return list<string> */
    public static function projectIds(array $index): array
    {
        $ids=[];foreach($index['files']??[]as$file){$id=self::projectId((string)($file['downloads'][0]??''));if($id!==null)$ids[]=$id;}return array_values(array_unique($ids));
    }

    public static function projectId(string $url): ?string
    {
        $parts=parse_url($url);if(($parts['host']??'')!=='cdn.modrinth.com')return null;$segments=explode('/',trim($parts['path']??'','/'));return($segments[0]??'')==='data'&&!empty($segments[1])?(string)$segments[1]:null;
    }

    public static function versionId(string $url): ?string
    { $parts=parse_url($url);$segments=explode('/',trim($parts['path']??'','/'));return($segments[0]??'')==='data'&&($segments[2]??'')==='versions'&&!empty($segments[3])?(string)$segments[3]:null; }

    /** @param list<string> $projectIds @return array<string,array<string,mixed>> */
    public function entries(array $projectIds): array
    { if($projectIds===[])return[];$marks=implode(',',array_fill(0,count($projectIds),'?'));$stmt=$this->db->prepare("SELECT * FROM project_catalog WHERE project_id IN ($marks)");$stmt->execute($projectIds);$entries=[];foreach($stmt->fetchAll()as$row){$row['project_data']=json_decode($row['project_json'],true)?:[];$row['versions_data']=json_decode($row['versions_json'],true)?:[];$entries[$row['project_id']]=$row;}return$entries; }

    /** @return array<string,mixed>|null */
    public function find(string $projectId): ?array
    { $entries=$this->entries([$projectId]);return$entries[$projectId]??null; }

    /** @return list<array<string,mixed>> */
    public function search(string $query,int $limit=20): array
    { $query=mb_strtolower(trim($query));if($query==='')return[];$rows=$this->db->query('SELECT * FROM project_catalog WHERE synced_at IS NOT NULL ORDER BY synced_at DESC')->fetchAll();$matches=[];foreach($rows as$row){$project=json_decode($row['project_json'],true)?:[];$haystack=mb_strtolower(implode(' ',[(string)($project['title']??''),(string)($project['slug']??''),(string)($project['description']??''),(string)$row['project_id']]));if(str_contains($haystack,$query)){$row['project_data']=$project;$row['versions_data']=json_decode($row['versions_json'],true)?:[];$matches[]=$row;if(count($matches)>=$limit)break;}}return$matches; }

    /** @param array<string,mixed> $project @param list<array<string,mixed>> $versions */
    public function save(string $id,array $project,array $versions,string $gameVersion,string $loader): void
    { $now=Database::now();$values=[json_encode($project,JSON_THROW_ON_ERROR),json_encode($versions,JSON_THROW_ON_ERROR),$gameVersion,$loader,$now,'',$now,$id];$update=$this->db->prepare('UPDATE project_catalog SET project_json=?,versions_json=?,game_version=?,loader=?,synced_at=?,last_error=?,updated_at=? WHERE project_id=?');$update->execute($values);if($update->rowCount()===0){$insert=$this->db->prepare('INSERT INTO project_catalog (project_json,versions_json,game_version,loader,synced_at,last_error,updated_at,project_id) VALUES (?,?,?,?,?,?,?,?)');$insert->execute($values);} }

    public function failure(string $id,string $gameVersion,string $loader,string $error): void
    { $now=Database::now();$error=mb_substr($error,0,500);$update=$this->db->prepare('UPDATE project_catalog SET game_version=?,loader=?,last_error=?,updated_at=? WHERE project_id=?');$update->execute([$gameVersion,$loader,$error,$now,$id]);if($update->rowCount()===0){$insert=$this->db->prepare('INSERT INTO project_catalog (project_id,project_json,versions_json,game_version,loader,synced_at,last_error,updated_at) VALUES (?,?,?,?,?,?,?,?)');$insert->execute([$id,'{}','[]',$gameVersion,$loader,null,$error,$now]);} }

    /** @return array{total:int,cached:int,failed:int,stale:int,last_synced:?string} */
    public function stats(array $projectIds): array
    { if($projectIds===[])return['total'=>0,'cached'=>0,'failed'=>0,'stale'=>0,'last_synced'=>null];$marks=implode(',',array_fill(0,count($projectIds),'?'));$stmt=$this->db->prepare("SELECT * FROM project_catalog WHERE project_id IN ($marks)");$stmt->execute($projectIds);$rows=$stmt->fetchAll();$cached=$failed=$stale=0;$latest=null;foreach($rows as$row){if($row['synced_at']){$cached++;if($latest===null||$row['synced_at']>$latest)$latest=$row['synced_at'];if(strtotime($row['synced_at'])<time()-86400)$stale++;}if($row['last_error']!=='')$failed++;}return['total'=>count($projectIds),'cached'=>$cached,'failed'=>$failed,'stale'=>$stale,'last_synced'=>$latest]; }
}
