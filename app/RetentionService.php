<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class RetentionService
{
    public function __construct(private readonly PDO$db,private readonly SystemSettings$settings){}

    /** @return array<string,int> */
    public function estimate():array
    {
        $policy=$this->settings->group('retention');$packages=$this->retainedCandidates('packages',(int)$policy['packages_keep']);$backups=$this->retainedCandidates('backups',(int)$policy['backups_keep']);$temp=$this->staleTemporaryFiles((int)$policy['temporary_hours']);$catalog=$this->staleCatalog((int)$policy['catalog_days']);$icons=$this->orphanedIcons((int)$policy['catalog_days']);$orphans=$this->reviewOnlyOrphanedFiles(max(30,(int)$policy['catalog_days']));
        $bytes=static fn(array$rows):int=>array_sum(array_map(fn($row)=>(int)($row['size']??(is_file((string)$row['path'])?filesize((string)$row['path']):0)),$rows));return['jobs'=>$this->countBefore('jobs','updated_at',(int)$policy['jobs_days'],"status IN ('completed','failed','cancelled')"),'audit'=>$this->countBefore('audit_log','created_at',(int)$policy['audit_days'],'1=1'),'notifications'=>$this->countBefore('notifications','created_at',(int)$policy['notifications_days'],'archived_at IS NOT NULL'),'security_events'=>$this->countBefore('security_events','created_at',(int)$policy['security_events_days'],'1=1'),'expired_tokens'=>$this->expiredTokens(),'packages'=>count($packages),'backups'=>count($backups),'temporary_files'=>count($temp),'stale_catalog'=>count($catalog),'orphaned_icons'=>count($icons),'review_only_orphaned_files'=>count($orphans),'review_only_file_bytes'=>$bytes($orphans),'file_bytes'=>$bytes(array_merge($packages,$backups,$temp,$icons))];
    }

    /** @return array<string,int> */
    public function cleanup(int$limit=500):array
    {
        $limit=max(1,min(1000,$limit));$counts=['password_reset_tokens'=>0,'email_verification_tokens'=>0,'auth_challenges'=>0,'jobs'=>0,'audit_log'=>0,'notifications'=>0,'security_events'=>0,'auth_attempts'=>0,'packages'=>0,'backups'=>0,'temporary_files'=>0,'stale_catalog'=>0,'orphaned_icons'=>0];$now=Database::now();foreach(['password_reset_tokens','email_verification_tokens','auth_challenges']as$table)$counts[$table]=$this->deleteBatch($table,'expires_at<?',[$now],$limit);
        $policy=$this->settings->group('retention');$counts['jobs']=$this->deleteBatch('jobs',"status IN ('completed','failed','cancelled') AND updated_at<?",[$this->cutoff((int)$policy['jobs_days'])],$limit);$counts['audit_log']=$this->deleteBatch('audit_log','created_at<?',[$this->cutoff((int)$policy['audit_days'])],$limit);$counts['notifications']=$this->deleteBatch('notifications','archived_at IS NOT NULL AND created_at<?',[$this->cutoff((int)$policy['notifications_days'])],$limit);$counts['security_events']=$this->deleteBatch('security_events','created_at<?',[$this->cutoff((int)$policy['security_events_days'])],$limit);$counts['auth_attempts']=$this->deleteBatch('auth_attempts','created_at<?',[$this->cutoff(30)],$limit);
        foreach(['packages'=>'packages_keep','backups'=>'backups_keep']as$table=>$setting)foreach(array_slice($this->retainedCandidates($table,(int)$policy[$setting]),0,$limit)as$row){$this->safeUnlink((string)$row['path']);$delete=$this->db->prepare("DELETE FROM {$table} WHERE id=?");$delete->execute([$row['id']]);$counts[$table]+=$delete->rowCount();}
        foreach(array_slice($this->staleTemporaryFiles((int)$policy['temporary_hours']),0,$limit)as$file)if($this->safeUnlink((string)$file['path']))$counts['temporary_files']++;
        foreach(array_slice($this->staleCatalog((int)$policy['catalog_days']),0,$limit)as$row){$delete=$this->db->prepare('DELETE FROM project_catalog WHERE project_id=?');$delete->execute([$row['project_id']]);$counts['stale_catalog']+=$delete->rowCount();}
        foreach(array_slice($this->orphanedIcons((int)$policy['catalog_days']),0,$limit)as$file)if($this->safeUnlink((string)$file['path']))$counts['orphaned_icons']++;
        return$counts;
    }

    private function countBefore(string$table,string$column,int$days,string$where):int
    { $stmt=$this->db->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where} AND {$column}<?");$stmt->execute([$this->cutoff($days)]);return(int)$stmt->fetchColumn(); }
    private function expiredTokens():int
    { $now=Database::now();$count=0;foreach(['password_reset_tokens','email_verification_tokens','auth_challenges']as$table){$stmt=$this->db->prepare("SELECT COUNT(*) FROM {$table} WHERE expires_at<?");$stmt->execute([$now]);$count+=(int)$stmt->fetchColumn();}return$count; }
    private function cutoff(int$days):string{return gmdate('c',time()-max(1,$days)*86400);}

    /** @param list<mixed> $params */
    private function deleteBatch(string$table,string$where,array$params,int$limit):int
    { if(!in_array($table,['password_reset_tokens','email_verification_tokens','auth_challenges','jobs','audit_log','notifications','security_events','auth_attempts'],true))throw new \InvalidArgumentException('Unsupported retention table.');$ids=$this->db->prepare("SELECT id FROM {$table} WHERE {$where} ORDER BY id LIMIT {$limit}");$ids->execute($params);$values=$ids->fetchAll(PDO::FETCH_COLUMN);if($values===[])return 0;$placeholders=implode(',',array_fill(0,count($values),'?'));$delete=$this->db->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})");$delete->execute($values);return$delete->rowCount();}

    /** @return list<array<string,mixed>> */
    private function retainedCandidates(string$table,int$keep):array
    { if(!in_array($table,['packages','backups'],true))throw new \InvalidArgumentException('Unsupported retained file table.');$keep=max(1,$keep);$rows=$this->db->query("SELECT * FROM {$table} ORDER BY pack_id,created_at DESC,id DESC")->fetchAll();$seen=[];$eligible=[];foreach($rows as$row){$pack=(string)$row['pack_id'];$seen[$pack]=($seen[$pack]??0)+1;if($seen[$pack]<=$keep)continue;$active=$this->db->prepare("SELECT COUNT(*) FROM jobs WHERE pack_id=? AND status IN ('queued','running')");$active->execute([$pack]);if((int)$active->fetchColumn()===0)$eligible[]=$row;}return$eligible;}

    /** @return list<array{path:string,size:int}> */
    private function staleTemporaryFiles(int$hours):array
    { $directory=MODRIGHT_ROOT.'/storage/temp';if(!is_dir($directory))return[];$protected=[];foreach($this->db->query('SELECT archive_path FROM import_reviews')->fetchAll(PDO::FETCH_COLUMN)as$path)$protected[(string)$path]=true;foreach($this->db->query("SELECT id FROM jobs WHERE status IN ('queued','running')")->fetchAll(PDO::FETCH_COLUMN)as$id)$protected[$directory.'/'.(string)$id.'.mrpack']=true;$cutoff=time()-max(1,$hours)*3600;$result=[];foreach(new \DirectoryIterator($directory)as$file){if(!$file->isFile()||$file->isLink()||$file->getMTime()>=$cutoff||isset($protected[$file->getPathname()]))continue;$result[]=['path'=>$file->getPathname(),'size'=>$file->getSize()];}return$result;}

    /** @return list<array{path:string,size:int}> */
    private function orphanedIcons(int$days):array
    { $directory=MODRIGHT_ROOT.'/storage/catalog-icons';if(!is_dir($directory))return[];$cutoff=time()-max(1,$days)*86400;$result=[];$exists=$this->db->prepare('SELECT COUNT(*) FROM project_catalog WHERE project_id=?');foreach(new \DirectoryIterator($directory)as$file){if(!$file->isFile()||$file->isLink()||$file->getMTime()>=$cutoff)continue;$exists->execute([$file->getFilename()]);if((int)$exists->fetchColumn()===0)$result[]=['path'=>$file->getPathname(),'size'=>$file->getSize()];}return$result;}

    /** @return list<array{project_id:string}> */
    private function staleCatalog(int$days):array
    { $used=[];foreach($this->db->query('SELECT index_json FROM packs')->fetchAll(PDO::FETCH_COLUMN)as$json){$index=json_decode((string)$json,true);foreach(is_array($index)?($index['files']??[]):[]as$file)if(is_array($file)){$project=trim((string)($file['project_id']??''));if($project==='')foreach($file['downloads']??[]as$url){$project=OfflineCatalog::projectId((string)$url)??'';if($project!=='')break;}if($project!=='')$used[$project]=true;}}$stmt=$this->db->prepare('SELECT project_id FROM project_catalog WHERE updated_at<? ORDER BY updated_at');$stmt->execute([$this->cutoff($days)]);return array_values(array_filter($stmt->fetchAll(),fn($row)=>!isset($used[(string)$row['project_id']])));}

    /** @return list<array{path:string,size:int}> */
    private function reviewOnlyOrphanedFiles(int$days):array
    { $known=[];foreach(['packages','backups']as$table)foreach($this->db->query("SELECT path FROM {$table}")->fetchAll(PDO::FETCH_COLUMN)as$path){$real=realpath((string)$path);$known[$real!==false?$real:(string)$path]=true;}$cutoff=time()-max(30,$days)*86400;$result=[];foreach(['packages','backups']as$directory){$root=MODRIGHT_ROOT.'/storage/'.$directory;if(!is_dir($root))continue;foreach(new \DirectoryIterator($root)as$file){if(!$file->isFile()||$file->isLink()||$file->getMTime()>=$cutoff)continue;$path=$file->getRealPath();if($path!==false&&!isset($known[$path]))$result[]=['path'=>$path,'size'=>$file->getSize()];}}return$result;}

    private function safeUnlink(string$path):bool
    { if(!is_file($path)||is_link($path))return false;$root=realpath(MODRIGHT_ROOT.'/storage');$parent=realpath(dirname($path));if($root!==false&&$parent!==false&&($parent===$root||str_starts_with($parent,$root.DIRECTORY_SEPARATOR)))return unlink($path);return false;}
}
