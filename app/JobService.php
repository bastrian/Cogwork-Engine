<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class JobService
{
    private readonly OfflineCatalog $catalog;
    private readonly MigrationService $migrations;
    public function __construct(
        private readonly PDO $db,
        private readonly PackRepository $packs,
        private readonly ModrinthClient $api = new ModrinthClient(),
        private readonly ArchiveService $archives = new ArchiveService(),
        ?OfflineCatalog $catalog = null,
    ) { $this->catalog=$catalog??new OfflineCatalog($db);$this->migrations=new MigrationService($db,$packs); }

    /** @param array<string, mixed> $payload */
    public function create(string $type, ?string $packId, array $payload = []): string
    {
        if ($packId !== null) {
            $this->packs->find($packId);
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM jobs WHERE pack_id=? AND status IN ('queued','running')");
            $stmt->execute([$packId]);
            if ((int) $stmt->fetchColumn() > 0) throw new HttpException(409, 'This pack already has an active job.');
        }
        $id = Database::id();
        $now = Database::now();
        $stmt = $this->db->prepare('INSERT INTO jobs (id,pack_id,type,status,payload,result,progress_current,progress_total,error,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$id, $packId, $type, 'queued', json_encode($payload, JSON_THROW_ON_ERROR), '{}', 0, 0, '', $now, $now]);
        $actor=(string)($_SESSION['user_id']??$_SESSION['admin_id']??'');if($actor!=='')$this->db->prepare('INSERT INTO job_actors (job_id,user_id) VALUES (?,?)')->execute([$id,$actor]);
        $audit=$this->db->prepare('INSERT INTO audit_log (id,action,context,created_at) VALUES (?,?,?,?)');
        $audit->execute([Database::id(),'job.created',json_encode(['job_id'=>$id,'pack_id'=>$packId,'type'=>$type,'actor_user_id'=>$_SESSION['user_id']??$_SESSION['admin_id']??null],JSON_THROW_ON_ERROR),$now]);
        return $id;
    }

    /** @return array<string, mixed> */
    public function find(string $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM jobs WHERE id=?'); $stmt->execute([$id]);
        $job = $stmt->fetch();
        if (!$job) throw new HttpException(404, 'Job not found.');
        $job['payload_data'] = json_decode($job['payload'], true, 512, JSON_THROW_ON_ERROR);
        $job['result_data'] = json_decode($job['result'], true, 512, JSON_THROW_ON_ERROR);
        return $job;
    }

    /** @return array<string, mixed> */
    public function step(string $id): array
    {
        $token = bin2hex(random_bytes(16));
        $stale = gmdate('c', time() - 300);
        $stmt = $this->db->prepare("UPDATE jobs SET lock_token=?,status='running',updated_at=? WHERE id=? AND status IN ('queued','running') AND (lock_token IS NULL OR updated_at < ?)");
        $stmt->execute([$token, Database::now(), $id, $stale]);
        if ($stmt->rowCount() !== 1) return $this->find($id);
        try {
            $job = $this->find($id);
            $method = 'run' . str_replace(' ', '', ucwords(str_replace('_', ' ', $job['type'])));
            if (!method_exists($this, $method)) throw new \RuntimeException('Unknown job type.');
            $this->{$method}($job);
        } catch (\Throwable $e) {
            $stmt = $this->db->prepare("UPDATE jobs SET status='failed',error=?,lock_token=NULL,updated_at=? WHERE id=? AND lock_token=?");
            $stmt->execute([mb_substr($e->getMessage(), 0, 2000), Database::now(), $id, $token]);
        }
        $stmt = $this->db->prepare('UPDATE jobs SET lock_token=NULL WHERE id=? AND lock_token=?'); $stmt->execute([$id, $token]);
        return $this->find($id);
    }

    /** @return array<string, mixed>|null */
    public function nextQueued(): ?array
    {
        $job = $this->db->query("SELECT id FROM jobs WHERE status IN ('queued','running') ORDER BY created_at LIMIT 1")->fetch();
        return $job ? $this->step($job['id']) : null;
    }

    public function cancel(string $id): void
    { $this->find($id);$stmt=$this->db->prepare("UPDATE jobs SET status='cancelled',error='Cancelled by user.',lock_token=NULL,updated_at=? WHERE id=? AND status IN ('queued','running')");$stmt->execute([Database::now(),$id]);if($stmt->rowCount()!==1)throw new HttpException(409,'This job can no longer be cancelled.'); }

    public function retry(string $id): string
    { $job=$this->find($id);if(!in_array($job['status'],['failed','cancelled'],true))throw new HttpException(409,'Only failed or cancelled jobs can be retried.');return$this->create($job['type'],$job['pack_id']?:null,$job['payload_data']); }

    /** @param array<string, mixed> $job */
    private function runUpdateCheck(array $job): void
    {
        $pack = $this->packs->find($job['pack_id']);
        $files = $pack['index']['files'];
        $cursor = (int) $job['progress_current'];
        $result = $job['result_data']; $result['updates'] ??= [];
        if ($cursor >= count($files)) { $this->complete($job['id'], $result); return; }
        $entry = $files[$cursor];
        $projectId = self::projectId($entry['downloads'][0] ?? '');
        if ($projectId !== null) {
            $versions = $this->api->projectVersions($projectId, $pack['game_version'], $pack['loader']);
            $latest = $versions[0] ?? null;
            $file = is_array($latest) ? self::primaryFile($latest['files'] ?? []) : null;
            if ($file) {
                $local = Storage::packPath($pack['id'], (string) $entry['path']);
                $hash = $entry['hashes']['sha1'] ?? null;
                $healthy = is_file($local) && (!$hash || hash_equals(strtolower((string) $hash), hash_file('sha1', $local)));
                $changed = ($file['url'] ?? '') !== ($entry['downloads'][0] ?? '');
                if ($changed || !$healthy) {
                    $result['updates'][] = ['index' => $cursor, 'project_id' => $projectId, 'from' => basename($entry['path']), 'to' => $file['filename'], 'version' => $latest['version_number'] ?? '', 'reason' => $changed ? 'update' : 'repair', 'file' => $file];
                }
            }
        }
        $this->progress($job['id'], $cursor + 1, count($files), $result);
    }

    /** @param array<string, mixed> $job */
    private function runApplyUpdates(array $job): void
    {
        $payload = $job['payload_data']; $updates = $payload['updates'] ?? [];
        $cursor = (int) $job['progress_current'];
        if ($cursor === 0 && empty($payload['backup_id'])) {
            $backup = $this->backup($job['pack_id']);
            $payload['backup_id'] = $backup;
            $this->savePayload($job['id'], $payload);
        }
        if ($cursor >= count($updates)) { $this->complete($job['id'], ['updated' => count($updates), 'backup_id' => $payload['backup_id']]); return; }
        $pack = $this->packs->find($job['pack_id']); $index = $pack['index']; $update = $updates[$cursor];
        $file = $update['file']; ModrinthClient::assertUrl($file['url']);
        $destination = Storage::packPath($pack['id'], 'mods/' . basename($file['filename']));
        $this->api->download($file['url'], $destination);
        $this->verify($destination, $file);
        $old = Storage::packPath($pack['id'], $index['files'][$update['index']]['path']);
        if (is_file($old) && realpath($old) !== realpath($destination)) @unlink($old);
        $index['files'][$update['index']]['path'] = 'mods/' . basename($file['filename']);
        $index['files'][$update['index']]['downloads'] = [$file['url']];
        $index['files'][$update['index']]['hashes'] = $file['hashes'];
        $index['files'][$update['index']]['fileSize'] = $file['size'];
        $this->packs->updateIndex($pack['id'], $index);
        $this->progress($job['id'], $cursor + 1, count($updates), ['updated' => $cursor + 1]);
    }

    /** @param array<string, mixed> $job */
    private function runBuild(array $job): void
    {
        $pack = $this->packs->find($job['pack_id']); $payload = $job['payload_data'];$rebuild=false;$manifestId=null;
        if(!empty($payload['manifest_id'])){$stmt=$this->db->prepare('SELECT * FROM build_manifests WHERE id=? AND pack_id=?');$stmt->execute([$payload['manifest_id'],$pack['id']]);$row=$stmt->fetch();if(!$row)throw new \RuntimeException('Build manifest no longer exists.');$manifest=json_decode($row['manifest_json'],true,512,JSON_THROW_ON_ERROR);$index=$manifest['index'];$payload=$manifest['build'];$rebuild=true;$manifestId=$row['id'];}
        else{$index = $pack['index']; $index['versionId'] = trim((string)$payload['version']); $index['summary'] = trim((string)($payload['summary'] ?? ''));}
        if (!empty($payload['dry_run'])) { $this->complete($job['id'], ['dry_run' => true, 'version' => $index['versionId']]); return; }
        if(!$rebuild)$this->backup($pack['id']);
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $pack['slug'] . '-' . $index['versionId']);
        $profile=(string)($payload['profile']??'standard');$definition=PackProfile::definition($profile);$target=$definition['target']??($payload['target']??'mrpack'); $built = [];
        try {
            if (in_array($target, ['mrpack', 'both'], true)) {
                $path = MODRIGHT_ROOT . '/storage/packages/' . Database::id() . '-' . $base . '.mrpack';
                $this->archives->build($path, $index, Storage::packPath($pack['id'], 'overrides'),$profile); $built[] = $path;
            }
            if (in_array($target, ['server', 'both'], true)) {
                $path = MODRIGHT_ROOT . '/storage/packages/' . Database::id() . '-' . $base . '-server.zip';
                $this->archives->buildServer($path, $index, Storage::packPath($pack['id']), !empty($payload['include_optional']),$payload['server_options']??[]); $built[] = $path;
            }
            if ($built === []) throw new \InvalidArgumentException('Invalid build target.');
        } catch (\Throwable $e) {
            foreach ($built as $path) @unlink($path);
            throw $e;
        }
        $packageIds=[];$packageData=[];foreach($built as$path){$packageIds[]=$this->recordPackage($pack['id'],$index['versionId'],$path);$packageData[]=['filename'=>basename($path),'size'=>filesize($path),'sha256'=>hash_file('sha256',$path)];}
        if(!$rebuild){$projects=(new OfflineCatalog($this->db))->entries(OfflineCatalog::projectIds($index));$catalog=[];foreach($projects as$id=>$entry)$catalog[$id]=['synced_at'=>$entry['synced_at'],'versions'=>count($entry['versions_data'])];$manifestId=Database::id();$manifest=['format'=>1,'index'=>$index,'build'=>['target'=>$payload['target']??'mrpack','profile'=>$profile,'include_optional'=>!empty($payload['include_optional']),'server_options'=>$payload['server_options']??[]],'validation'=>$payload['validation']??[],'catalog'=>$catalog,'packages'=>$packageData,'created_at'=>Database::now()];$stmt=$this->db->prepare('INSERT INTO build_manifests (id,pack_id,version_id,manifest_json,created_at) VALUES (?,?,?,?,?)');$stmt->execute([$manifestId,$pack['id'],$index['versionId'],json_encode($manifest,JSON_THROW_ON_ERROR),Database::now()]);$this->packs->updateIndex($pack['id'], $index);}
        $this->complete($job['id'], ['package_ids' => $packageIds,'manifest_id'=>$manifestId,'rebuild'=>$rebuild]);
    }

    /** @param array<string, mixed> $job */
    private function runSyncFiles(array $job): void
    {
        $pack = $this->packs->find($job['pack_id']);
        $files = $pack['index']['files']; $cursor = (int) $job['progress_current'];
        if ($cursor >= count($files)) { $this->complete($job['id'], ['synced' => count($files)]); return; }
        $entry = $files[$cursor]; $url = $entry['downloads'][0] ?? '';
        PackRepository::assertRelativePath($entry['path']);
        $destination = Storage::packPath($pack['id'], $entry['path']);
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) throw new \RuntimeException('Cannot create mod directory.');
        $healthy = is_file($destination) && (!isset($entry['hashes']['sha1']) || hash_equals(strtolower($entry['hashes']['sha1']), hash_file('sha1', $destination)));
        if(!empty($entry['local'])&&!$healthy)throw new \RuntimeException('Local mod is missing or changed: '.$entry['path']);
        if($url===''&&empty($entry['local']))throw new \RuntimeException('Mod entry has no download URL.');
        $action='Verified existing file';$actionCode='job.file_verified';
        if (!$healthy) { $this->api->download($url, $destination); $this->verify($destination, ['size' => $entry['fileSize'] ?? null, 'hashes' => $entry['hashes'] ?? []]);$action='Downloaded and verified';$actionCode='job.file_downloaded'; }
        $result=$job['result_data'];$activity=is_array($result['activity']??null)?$result['activity']:[];
        array_unshift($activity,['file'=>basename((string)$entry['path']),'action'=>$action,'action_code'=>$actionCode]);$result=['synced'=>$cursor+1,'current_file'=>basename((string)$entry['path']),'current_action'=>$action,'current_action_code'=>$actionCode,'next_file'=>isset($files[$cursor+1])?basename((string)$files[$cursor+1]['path']):null,'bytes'=>(int)($result['bytes']??0)+(int)($entry['fileSize']??0),'activity'=>array_slice($activity,0,5)];
        $this->progress($job['id'], $cursor + 1, count($files), $result);
    }

    /** @param array<string,mixed> $job */
    private function runCatalogSync(array $job): void
    {
        $pack=$this->packs->find($job['pack_id']);$ids=$job['payload_data']['project_ids']??OfflineCatalog::projectIds($pack['index']);$cursor=(int)$job['progress_current'];$result=$job['result_data'];$result['refreshed']??=0;$result['failed']??=0;$result['activity']??=[];
        if($cursor>=count($ids)){$this->complete($job['id'],$result);return;}$id=(string)$ids[$cursor];
        try{$project=$this->api->project($id);$versions=$this->api->projectVersions($id,$pack['game_version'],$pack['loader']);$this->catalog->save($id,$project,$versions,$pack['game_version'],$pack['loader']);if(!empty($project['icon_url'])){try{$this->api->download((string)$project['icon_url'],Storage::catalogIconPath($id),2*1024*1024);}catch(\Throwable){}}$result['refreshed']++;$action='Metadata and versions saved';$actionCode='job.catalog_saved';$name=(string)($project['title']??$project['slug']??$id);}
        catch(\Throwable$e){$this->catalog->failure($id,$pack['game_version'],$pack['loader'],$e->getMessage());$result['failed']++;$action='Refresh failed; older cache kept';$actionCode='job.catalog_failed';$name=$id;}
        array_unshift($result['activity'],['file'=>$name,'action'=>$action,'action_code'=>$actionCode]);$result['activity']=array_slice($result['activity'],0,5);$result['current_file']=$name;$result['current_action']=$action;$result['current_action_code']=$actionCode;$result['next_file']=$ids[$cursor+1]??null;
        $this->progress($job['id'],$cursor+1,count($ids),$result);
    }

    /** @param array<string,mixed> $job */
    private function runMigrationScan(array $job): void
    {
        $scanId=(string)($job['payload_data']['scan_id']??'');$scan=$this->migrations->find($scanId);$pack=$this->packs->find($job['pack_id']);$targets=$scan['options']['targets'];$files=$pack['index']['files'];$total=count($targets)*count($files);$cursor=(int)$job['progress_current'];$result=$job['result_data'];$result['activity']??=[];
        if($cursor===0){$stmt=$this->db->prepare("UPDATE migration_scans SET status='running',updated_at=? WHERE id=?");$stmt->execute([Database::now(),$scanId]);}
        if($cursor>=$total){$this->migrations->complete($scanId);$this->complete($job['id'],['scan_id'=>$scanId,'targets'=>count($targets),'mods'=>count($files)]);return;}
        $target=$targets[intdiv($cursor,max(1,count($files)))];$fileIndex=$cursor%max(1,count($files));$file=$files[$fileIndex];$projectId=OfflineCatalog::projectId((string)($file['downloads'][0]??''))??'';$name=basename((string)$file['path']);$error='';
        try{$versions=$projectId===''?[]:$this->api->projectVersions($projectId,$target['game'],$target['loader']);$classified=MigrationService::classify($file,$versions,!empty($scan['options']['allow_beta']));if($classified['classification']==='incompatible'&&$projectId!==''){ $replacement=$this->migrations->replacementFor($projectId,$target['loader']);if($replacement){$replacementVersions=$this->api->projectVersions((string)$replacement['replacement_project_id'],$target['game'],$target['loader']);$candidate=MigrationService::classify(['downloads'=>['https://cdn.modrinth.com/data/'.$replacement['replacement_project_id'].'/versions/probe/probe.jar']],$replacementVersions,!empty($scan['options']['allow_beta']));if($candidate['classification']==='direct'){$candidate['classification']='replacement';$candidate['evidence']['replacement_project_id']=$replacement['replacement_project_id'];$candidate['evidence']['confidence']=$replacement['confidence'];$candidate['evidence']['reason']='Curated cross-loader replacement: '.$replacement['note'];$classified=$candidate;}}}if(in_array($classified['classification'],['direct','replacement'],true)){$packProjects=OfflineCatalog::projectIds($pack['index']);$targetRequired=[];foreach($classified['evidence']['dependencies']??[]as$dependency)if(($dependency['dependency_type']??'')==='required'&&!empty($dependency['project_id']))$targetRequired[]=(string)$dependency['project_id'];$currentRequired=[];$currentVersionId=OfflineCatalog::versionId((string)($file['downloads'][0]??''));if($currentVersionId!==null){try{$currentVersion=$this->api->version($currentVersionId);foreach($currentVersion['dependencies']??[]as$dependency)if(($dependency['dependency_type']??'')==='required'&&!empty($dependency['project_id']))$currentRequired[]=(string)$dependency['project_id'];}catch(\Throwable){}}$additions=array_values(array_diff(array_unique($targetRequired),array_unique($currentRequired),$packProjects));$removed=array_values(array_diff(array_unique($currentRequired),array_unique($targetRequired)));$dependencyFiles=[];$unavailable=[];foreach($additions as$dependencyId){try{$dependencyVersions=$this->api->projectVersions($dependencyId,$target['game'],$target['loader']);$dependencyCandidate=MigrationService::classify(['downloads'=>['https://cdn.modrinth.com/data/'.$dependencyId.'/versions/probe/probe.jar']],$dependencyVersions,!empty($scan['options']['allow_beta']));if($dependencyCandidate['classification']==='direct')$dependencyFiles[]=['project_id'=>$dependencyId]+$dependencyCandidate['evidence'];else$unavailable[]=$dependencyId;}catch(\Throwable){$unavailable[]=$dependencyId;}}$classified['evidence']['dependency_additions']=$additions;$classified['evidence']['dependency_removed']=$removed;$classified['evidence']['dependency_files']=$dependencyFiles;$classified['evidence']['dependency_unavailable']=$unavailable;if($unavailable!==[]){$classified['classification']='manual_review';$classified['evidence']['reason']='A required target dependency is unavailable; manual review is required.';}}$classified['evidence']['environment']=$file['env']??['client'=>'required','server'=>'required'];$action=ucwords(str_replace('_',' ',$classified['classification']));}
        catch(\Throwable$e){$error=$e->getMessage();$classified=['classification'=>'unknown','evidence'=>['reason'=>'Compatibility service unavailable; this result is unknown, not incompatible.']];$action='Unknown — service unavailable';}
        $this->migrations->saveResult($scanId,$target['game'],$target['loader'],$target['loader_version'],$projectId,$fileIndex,$classified,$error);array_unshift($result['activity'],['file'=>$name,'action'=>$target['game'].' '.$target['loader'].': '.$action]);$result['activity']=array_slice($result['activity'],0,5);$result['current_file']=$name;$result['current_action']=$action;$result['next_file']=$files[($fileIndex+1)%max(1,count($files))]['path']??null;$result['scan_id']=$scanId;$this->progress($job['id'],$cursor+1,$total,$result);
    }

    /** @param array<string, mixed> $job */
    private function runImport(array $job): void
    {
        $payload = $job['payload_data'];
        if (empty($payload['archive'])) {
            $version = $this->api->version($payload['version_id']);
            $file = self::primaryFile($version['files'] ?? []);
            if (!$file) throw new \RuntimeException('Modrinth version has no downloadable file.');
            $archive = MODRIGHT_ROOT . '/storage/temp/' . $job['id'] . '.mrpack';
            $this->api->download($file['url'], $archive, 2147483648);
            $payload['archive'] = $archive; $this->savePayload($job['id'], $payload);
            $this->progress($job['id'], 1, 2, []); return;
        }
        $index = $this->archives->readIndex($payload['archive']);$reviewId=Database::id();$stmt=$this->db->prepare('INSERT INTO import_reviews (id,archive_path,index_json,source,created_at) VALUES (?,?,?,?,?)');$stmt->execute([$reviewId,$payload['archive'],PackRepository::encode($index),'Modrinth version '.($payload['version_id']??''),Database::now()]);$actor=$this->db->prepare('SELECT user_id FROM job_actors WHERE job_id=?');$actor->execute([$job['id']]);$userId=$actor->fetchColumn();if($userId)$this->db->prepare('INSERT INTO import_review_owners (review_id,user_id) VALUES (?,?)')->execute([$reviewId,$userId]);$this->complete($job['id'], ['review_id' => $reviewId]);
    }

    /** @param array<string, mixed> $file */
    private function verify(string $path, array $file): void
    {
        if (isset($file['size']) && filesize($path) !== (int) $file['size']) { @unlink($path); throw new \RuntimeException('Downloaded file size mismatch.'); }
        foreach (['sha1', 'sha512'] as $algorithm) {
            if (!empty($file['hashes'][$algorithm]) && !hash_equals(strtolower($file['hashes'][$algorithm]), hash_file($algorithm, $path))) { @unlink($path); throw new \RuntimeException("Downloaded {$algorithm} mismatch."); }
        }
    }

    private function backup(string $packId): string
    {
        $pack = $this->packs->find($packId); $id = Database::id();
        $path = MODRIGHT_ROOT . '/storage/backups/' . $id . '.json';
        Storage::atomicWrite($path, PackRepository::encode($pack['index']));
        $stmt = $this->db->prepare('INSERT INTO backups (id,pack_id,version_id,path,created_at) VALUES (?,?,?,?,?)');
        $stmt->execute([$id, $packId, $pack['version_id'], $path, Database::now()]);
        return $id;
    }

    private function recordPackage(string $packId, string $version, string $path): string
    {
        $id=Database::id();$stmt=$this->db->prepare('INSERT INTO packages (id,pack_id,version_id,path,size,sha256,created_at) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$id,$packId,$version,$path,filesize($path),hash_file('sha256',$path),Database::now()]);return$id;
    }

    public function restoreBackup(string $backupId): string
    {
        $stmt=$this->db->prepare('SELECT * FROM backups WHERE id=?');$stmt->execute([$backupId]);$backup=$stmt->fetch();
        if(!$backup||!is_file($backup['path']))throw new HttpException(404,'Backup not found.');
        $index=json_decode((string)file_get_contents($backup['path']),true,512,JSON_THROW_ON_ERROR);
        $this->backup($backup['pack_id']);
        $this->packs->updateIndex($backup['pack_id'],$index);
        return $backup['pack_id'];
    }

    /** @param array<string, mixed> $result */
    private function progress(string $id, int $current, int $total, array $result): void
    { $stmt=$this->db->prepare('UPDATE jobs SET progress_current=?,progress_total=?,result=?,updated_at=? WHERE id=?'); $stmt->execute([$current,$total,json_encode($result,JSON_THROW_ON_ERROR),Database::now(),$id]); }
    /** @param array<string, mixed> $result */
    private function complete(string $id, array $result): void
    { $stmt=$this->db->prepare("UPDATE jobs SET status='completed',result=?,progress_current=progress_total,updated_at=? WHERE id=?"); $stmt->execute([json_encode($result,JSON_THROW_ON_ERROR),Database::now(),$id]); }
    /** @param array<string, mixed> $payload */
    private function savePayload(string $id, array $payload): void
    { $stmt=$this->db->prepare('UPDATE jobs SET payload=?,updated_at=? WHERE id=?'); $stmt->execute([json_encode($payload,JSON_THROW_ON_ERROR),Database::now(),$id]); }

    /** @param list<array<string,mixed>> $files @return array<string,mixed>|null */
    private static function primaryFile(array $files): ?array
    { foreach ($files as $file) if (!empty($file['primary'])) return $file; return $files[0] ?? null; }
    private static function projectId(string $url): ?string
    { $parts=parse_url($url); if (($parts['host']??'')!=='cdn.modrinth.com') return null; $segments=explode('/',trim($parts['path']??'','/')); return ($segments[0]??'')==='data' ? ($segments[1]??null) : null; }
}
