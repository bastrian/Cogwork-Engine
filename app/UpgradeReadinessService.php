<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class UpgradeReadinessService
{
    public function __construct(private readonly PDO$db,private readonly SystemSettings$settings){}

    /** @param array<string,mixed> $server @param array<string,mixed>|null $update @return array<string,mixed> */
    public function report(array$server=[],?array$update=null):array
    {
        $health=(new HealthService($this->db,$this->settings))->report($server);$blockers=[];$warnings=[];
        foreach($health['checks']as$check){if($check['status']==='misconfigured')$blockers[]=$check['id'].': '.$check['message'];elseif(in_array($check['status'],['degraded','unknown'],true))$warnings[]=$check['id'].': '.$check['message'];}
        $active=(int)$this->db->query("SELECT COUNT(*) FROM jobs WHERE status IN ('queued','running')")->fetchColumn();if($active>0)$blockers[]=$active.' background job(s) must finish or be cancelled before upgrade.';
        $backup=(string)($this->db->query("SELECT MAX(created_at) FROM audit_log WHERE action='backup.exported'")->fetchColumn()?:'');if($backup===''||strtotime($backup)<time()-7*86400)$warnings[]='No successfully downloaded application backup from the last seven days was found. Create and download a fresh backup first.';
        $target=$update['release']['version']??null;$assets=$update['release']['assets']??[];$zip=false;$checksum=false;foreach(is_array($assets)?$assets:[]as$asset){$name=(string)($asset['name']??'');if(str_ends_with($name,'.zip'))$zip=true;if(str_ends_with($name,'.zip.sha256'))$checksum=true;}if($target!==null&&(!$zip||!$checksum))$warnings[]='The selected release does not expose both the expected shared-hosting ZIP and SHA-256 asset.';$requirements=$update['release']['requirements']??[];if(is_array($requirements)){if(!empty($requirements['php'])&&version_compare(PHP_VERSION,(string)$requirements['php'],'<'))$blockers[]='The selected release requires PHP '.(string)$requirements['php'].' or newer.';foreach(is_array($requirements['extensions']??null)?$requirements['extensions']:[]as$extension)if(!extension_loaded((string)$extension))$blockers[]='The selected release requires the missing PHP extension '.(string)$extension.'.';}
        $modifications=$this->localModifications();if($modifications['status']==='modified')$warnings[]=$modifications['count'].' application file(s) differ from the installed release manifest and may be overwritten.';elseif($modifications['status']==='unknown')$warnings[]='This installation has no release file manifest, so local application modifications cannot be detected.';
        return['status'=>$blockers?'blocked':($warnings?'warning':'ready'),'current_version'=>$health['version'],'target_version'=>$target,'generated_at'=>Database::now(),'blockers'=>$blockers,'warnings'=>$warnings,'backup'=>['last_recorded'=>$backup?:null,'fresh'=>$backup!==''&&strtotime($backup)>=time()-7*86400],'active_jobs'=>$active,'release_requirements'=>is_array($requirements)?$requirements:[],'release_assets'=>['zip'=>$zip,'sha256'=>$checksum],'local_modifications'=>$modifications,'instructions'=>['Create and download a fresh application/database backup.','Stop cron and prevent new jobs before replacing application files.','Verify the release ZIP against its published SHA-256 file.','Replace application files while preserving protected configuration and storage.','Load the application once to apply additive migrations, then run System diagnostics.','If verification fails, restore the previous application files and database backup together.'],'privacy'=>'No credentials, tokens, database connection details, private paths, personal data, or remote response bodies are included.'];
    }

    /** @return array{status:string,count:int,files:list<string>} */
    private function localModifications():array
    {
        $path=MODRIGHT_ROOT.'/.release-manifest.sha256';if(!is_file($path)||filesize($path)>2*1024*1024)return['status'=>'unknown','count'=>0,'files'=>[]];$changed=[];
        foreach(file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[]as$line){if(!preg_match('/^([a-f0-9]{64})\s+\*?(.+)$/',$line,$match))continue;$relative=str_replace('\\','/',$match[2]);if(str_starts_with($relative,'/')||in_array('..',explode('/',$relative),true))continue;$file=MODRIGHT_ROOT.'/'.$relative;if(!is_file($file)||!hash_equals($match[1],hash_file('sha256',$file)))$changed[]=$relative;if(count($changed)>=100)break;}
        return['status'=>$changed?'modified':'clean','count'=>count($changed),'files'=>$changed];
    }
}
