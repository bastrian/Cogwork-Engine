<?php

declare(strict_types=1);

namespace Modright;

use PDO;

final class MigrationService
{
    public function __construct(private readonly PDO $db, private readonly PackRepository $packs) {}

    /** @param list<array{game:string,loader:string,loader_version:string}> $targets */
    public function createScan(string $packId, array $targets, bool $allowBeta = false): string
    {
        $pack=$this->packs->find($packId);$clean=[];
        foreach($targets as$target){$game=trim((string)($target['game']??''));$loader=(string)($target['loader']??'');$loaderVersion=trim((string)($target['loader_version']??''));if($game===''||$loaderVersion===''||!in_array($loader,['fabric','forge','neoforge','quilt'],true))continue;$clean[$game.'|'.$loader]=['game'=>$game,'loader'=>$loader,'loader_version'=>$loaderVersion];}
        if($clean===[])throw new \InvalidArgumentException('Select at least one valid migration target.');
        if(count($clean)>20)throw new \InvalidArgumentException('A scan can compare at most 20 targets.');
        $id=Database::id();$now=Database::now();$fingerprint=hash('sha256',PackRepository::encode($pack['index']));$stmt=$this->db->prepare('INSERT INTO migration_scans (id,pack_id,status,options_json,summary_json,source_fingerprint,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)');$stmt->execute([$id,$packId,'queued',json_encode(['targets'=>array_values($clean),'allow_beta'=>$allowBeta],JSON_THROW_ON_ERROR),'{}',$fingerprint,$now,$now]);return$id;
    }

    /** @return array<string,mixed> */
    public function find(string $id): array
    { $stmt=$this->db->prepare('SELECT * FROM migration_scans WHERE id=?');$stmt->execute([$id]);$scan=$stmt->fetch();if(!$scan)throw new HttpException(404,'Migration scan not found.');$scan['options']=json_decode($scan['options_json'],true,512,JSON_THROW_ON_ERROR);$scan['summary']=json_decode($scan['summary_json'],true,512,JSON_THROW_ON_ERROR);return$scan; }

    /** @return list<array<string,mixed>> */
    public function results(string $scanId): array
    { $stmt=$this->db->prepare('SELECT * FROM migration_results WHERE scan_id=? ORDER BY target_game,target_loader,file_index');$stmt->execute([$scanId]);$rows=$stmt->fetchAll();foreach($rows as&$row)$row['evidence']=json_decode($row['evidence_json'],true)?:[];return$rows; }

    /** @return array<string,mixed>|null */
    public function replacementFor(string $projectId,string $loader): ?array
    { $stmt=$this->db->prepare('SELECT * FROM migration_replacements WHERE source_project_id=? AND target_loader=?');$stmt->execute([$projectId,$loader]);return$stmt->fetch()?:null; }

    /** @return list<array<string,mixed>> */
    public function replacements(): array
    { return$this->db->query('SELECT * FROM migration_replacements ORDER BY source_project_id,target_loader')->fetchAll(); }

    public function saveReplacement(string $source,string $loader,string $replacement,string $confidence,string $note): void
    { if($source===''||$replacement===''||$source===$replacement)throw new \InvalidArgumentException('Enter different source and replacement project IDs.');if(!in_array($loader,['fabric','forge','neoforge','quilt'],true)||!in_array($confidence,['low','medium','high'],true))throw new \InvalidArgumentException('Invalid loader or confidence.');$this->db->prepare('DELETE FROM migration_replacements WHERE source_project_id=? AND target_loader=?')->execute([$source,$loader]);$stmt=$this->db->prepare('INSERT INTO migration_replacements (source_project_id,target_loader,replacement_project_id,confidence,note,updated_at) VALUES (?,?,?,?,?,?)');$stmt->execute([$source,$loader,$replacement,$confidence,mb_substr($note,0,500),Database::now()]); }

    public function deleteReplacement(string $source,string $loader): void
    { $this->db->prepare('DELETE FROM migration_replacements WHERE source_project_id=? AND target_loader=?')->execute([$source,$loader]); }

    /** @param array<int,string> $importance */
    public function setImportance(string $scanId,array $importance): void
    { $scan=$this->find($scanId);$clean=[];foreach($importance as$i=>$value)if(in_array($value,['essential','normal','optional'],true))$clean[(int)$i]=$value;$options=$scan['options'];$options['importance']=$clean;$stmt=$this->db->prepare('UPDATE migration_scans SET options_json=?,updated_at=? WHERE id=?');$stmt->execute([json_encode($options,JSON_THROW_ON_ERROR),Database::now(),$scanId]); }

    /** @param array<string,mixed> $file @param list<array<string,mixed>> $versions @return array{classification:string,evidence:array<string,mixed>} */
    public static function classify(array $file, array $versions, bool $allowBeta=false): array
    {
        if(!empty($file['local'])||OfflineCatalog::projectId((string)($file['downloads'][0]??''))===null)return['classification'=>'unknown','evidence'=>['reason'=>'Local or externally hosted file; review manually.']];
        $eligible=array_values(array_filter($versions,static fn(array$v):bool=>$allowBeta||($v['version_type']??'release')==='release'));
        if($eligible===[])return['classification'=>'incompatible','evidence'=>['reason'=>'No published compatible version was found.']];
        usort($eligible,static fn(array$a,array$b):int=>strcmp((string)($b['date_published']??''),(string)($a['date_published']??'')));
        $version=$eligible[0];$selected=self::primaryFile($version['files']??[]);if(!$selected)return['classification'=>'manual_review','evidence'=>['reason'=>'A compatible version exists but has no downloadable file.','version'=>$version]];
        return['classification'=>'direct','evidence'=>['version_id'=>$version['id']??'','version_number'=>$version['version_number']??'','version_type'=>$version['version_type']??'release','file'=>$selected,'dependencies'=>$version['dependencies']??[],'reason'=>'The same project publishes a compatible target version.']];
    }

    /** @param list<array<string,mixed>> $rows @param array<int,string> $importance @return array<string,mixed> */
    public static function summarize(array $rows,array $importance=[]): array
    {
        $counts=['direct'=>0,'replacement'=>0,'incompatible'=>0,'unknown'=>0,'manual_review'=>0];$environments=['client_portable'=>0,'server_portable'=>0,'client_only'=>0,'server_only'=>0,'optional'=>0];$points=0;$essentialBlocked=0;
        foreach($rows as$row){$class=(string)$row['classification'];$counts[$class]=($counts[$class]??0)+1;$weight=($importance[(int)$row['file_index']]??'normal')==='essential'?4:(($importance[(int)$row['file_index']]??'normal')==='optional'?0.5:1);$points+=match($class){'direct'=>100*$weight,'replacement'=>65*$weight,'manual_review'=>25*$weight,'unknown'=>10*$weight,default=>0};if($weight===4&&!in_array($class,['direct','replacement'],true))$essentialBlocked++;$e=$row['evidence']??(isset($row['evidence_json'])?(json_decode((string)$row['evidence_json'],true)?:[]):[]);$env=$e['environment']??['client'=>'required','server'=>'required'];if(($env['server']??'required')==='unsupported')$environments['client_only']++;if(($env['client']??'required')==='unsupported')$environments['server_only']++;if(($env['client']??'required')==='optional'||($env['server']??'required')==='optional')$environments['optional']++;if(in_array($class,['direct','replacement'],true)){if(($env['client']??'required')!=='unsupported')$environments['client_portable']++;if(($env['server']??'required')!=='unsupported')$environments['server_portable']++;}}
        $total=count($rows);$max=max(1,array_sum(array_map(fn($r)=>(($importance[(int)$r['file_index']]??'normal')==='essential'?4:(($importance[(int)$r['file_index']]??'normal')==='optional'?0.5:1))*100,$rows)));$score=(int)round($points/$max*100);if($essentialBlocked>0)$score=max(0,$score-min(60,$essentialBlocked*15));return['total'=>$total,'counts'=>$counts,'environments'=>$environments,'score'=>$score,'essential_blocked'=>$essentialBlocked];
    }

    /** @return list<array<string,mixed>> */
    public function summaries(string $scanId): array
    { $scan=$this->find($scanId);$groups=[];foreach($this->results($scanId)as$row){$key=$row['target_game'].'|'.$row['target_loader'];$groups[$key][]=$row;}$summaries=[];foreach($scan['options']['targets']as$target){$key=$target['game'].'|'.$target['loader'];$summary=self::summarize($groups[$key]??[],$scan['options']['importance']??[]);$summary['game']=$target['game'];$summary['loader']=$target['loader'];$summary['loader_version']=$target['loader_version'];$summaries[]=$summary;}usort($summaries,fn($a,$b)=>[$a['essential_blocked'],-$a['score']]<=>[$b['essential_blocked'],-$b['score']]);foreach($summaries as$i=>&$summary)$summary['recommendation']=$i===0?'Safest target':($i===1?'Best alternative':'');return$summaries; }

    /** @param array<string,mixed> $version */
    public function saveResult(string $scanId,string $game,string $loader,string $loaderVersion,string $projectId,int $fileIndex,array $classified,string $error=''): void
    { $this->db->prepare('DELETE FROM migration_results WHERE scan_id=? AND target_game=? AND target_loader=? AND file_index=?')->execute([$scanId,$game,$loader,$fileIndex]);$stmt=$this->db->prepare('INSERT INTO migration_results (scan_id,target_game,target_loader,loader_version,project_id,file_index,classification,evidence_json,checked_at,error) VALUES (?,?,?,?,?,?,?,?,?,?)');$stmt->execute([$scanId,$game,$loader,$loaderVersion,$projectId,$fileIndex,$classified['classification'],json_encode($classified['evidence'],JSON_THROW_ON_ERROR),Database::now(),mb_substr($error,0,500)]); }

    public function complete(string $scanId): void
    { $summaries=$this->summaries($scanId);$stmt=$this->db->prepare("UPDATE migration_scans SET status='completed',summary_json=?,updated_at=? WHERE id=?");$stmt->execute([json_encode($summaries,JSON_THROW_ON_ERROR),Database::now(),$scanId]); }

    /** @param list<int> $accepted @param list<int> $removed */
    public function apply(string $scanId,string $game,string $loader,array $accepted,array $removed=[]): string
    {
        $scan=$this->find($scanId);if($scan['status']!=='completed')throw new HttpException(409,'The migration scan is not complete.');$source=$this->packs->find($scan['pack_id']);if(!hash_equals($scan['source_fingerprint'],hash('sha256',PackRepository::encode($source['index']))))throw new HttpException(409,'The source pack changed after this scan. Run a new migration scan.');$target=null;foreach($scan['options']['targets']as$t)if($t['game']===$game&&$t['loader']===$loader)$target=$t;if(!$target)throw new HttpException(400,'Unknown migration target.');
        $stmt=$this->db->prepare('SELECT * FROM migration_results WHERE scan_id=? AND target_game=? AND target_loader=? ORDER BY file_index');$stmt->execute([$scanId,$game,$loader]);$rows=$stmt->fetchAll();$index=$source['index'];$newFiles=[];$decisions=[];
        foreach($rows as$row){$i=(int)$row['file_index'];$e=json_decode($row['evidence_json'],true)?:[];$file=$index['files'][$i]??null;if(!$file)continue;if(in_array($i,$accepted,true)&&in_array($row['classification'],['direct','replacement'],true)&&is_array($e['file']??null)){$f=$e['file'];$file['path']='mods/'.basename((string)$f['filename']);$file['downloads']=[(string)$f['url']];$file['hashes']=$f['hashes'];$file['fileSize']=$f['size'];$newFiles[]=$file;foreach($e['dependency_files']??[]as$dependency){$df=$dependency['file']??null;if(!is_array($df))continue;$newFiles[]=['path'=>'mods/'.basename((string)$df['filename']),'downloads'=>[(string)$df['url']],'hashes'=>$df['hashes'],'fileSize'=>$df['size'],'env'=>['client'=>'required','server'=>'required']];}$decisions[]=['index'=>$i,'action'=>'updated','version_id'=>$e['version_id']??'','dependencies_added'=>$e['dependency_additions']??[],'dependencies_removed'=>$e['dependency_removed']??[]];}elseif($row['classification']==='direct'){$newFiles[]=$file;$decisions[]=['index'=>$i,'action'=>'kept-current'];}else{$newFiles[]=$file;$decisions[]=['index'=>$i,'action'=>'manual-review','classification'=>$row['classification']];}}
        $deduplicated=[];foreach($newFiles as$newFile){$key=(string)($newFile['downloads'][0]??$newFile['path']);$deduplicated[$key]=$newFile;}$newFiles=array_values($deduplicated);
        $index['files']=$newFiles;$index['name']=$source['name'].' — '.$game.' '.$loader;$index['versionId']=$source['version_id'].'-migration-'.$game.'-'.$loader;$index['summary']='Migration copy of '.$source['name'].'; runtime testing required.';$index['dependencies']['minecraft']=$game;foreach(['fabric-loader','forge','neoforge','quilt-loader']as$key)unset($index['dependencies'][$key]);$index['dependencies'][match($loader){'fabric'=>'fabric-loader','quilt'=>'quilt-loader',default=>$loader}]=$target['loader_version'];$targetId=null;
        try{$targetId=$this->packs->create($index);$this->copyPackData($source['id'],$targetId);$optionsService=new PackOptions($this->db);$options=$optionsService->get($source['id']);$options['java_version']=self::recommendedJava($game);$optionsService->save($targetId,$options);$manifest=['format'=>1,'scan_id'=>$scanId,'source_pack_id'=>$source['id'],'target_pack_id'=>$targetId,'source'=>['minecraft'=>$source['game_version'],'loader'=>$source['loader'],'loader_version'=>$source['loader_version']],'target'=>$target,'decisions'=>$decisions,'evidence_checked_at'=>$scan['updated_at'],'warnings'=>['Published compatibility does not guarantee runtime compatibility. Back up worlds and perform a launch test.','Review configuration files and scripts when changing loaders.'],'created_at'=>Database::now()];$stmt=$this->db->prepare('INSERT INTO migration_manifests (id,scan_id,source_pack_id,target_pack_id,manifest_json,created_at) VALUES (?,?,?,?,?,?)');$stmt->execute([Database::id(),$scanId,$source['id'],$targetId,json_encode($manifest,JSON_THROW_ON_ERROR),Database::now()]);return$targetId;}catch(\Throwable$e){if($targetId!==null){try{$this->packs->delete($targetId);}catch(\Throwable){}}throw$e;}
    }

    private function copyPackData(string $sourceId,string $targetId): void
    { foreach(['overrides','server-overrides']as$folder){$source=Storage::packPath($sourceId,$folder);$target=Storage::packPath($targetId,$folder);if(!is_dir($source))continue;$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);foreach($iterator as$item){$relative=substr($item->getPathname(),strlen($source)+1);$dest=$target.'/'.$relative;if($item->isDir()){if(!is_dir($dest))mkdir($dest,0770,true);}else{if(!is_dir(dirname($dest)))mkdir(dirname($dest),0770,true);copy($item->getPathname(),$dest);}}} }

    private static function recommendedJava(string $game): int
    { if(version_compare($game,'1.20.5','>='))return 21;if(version_compare($game,'1.18','>='))return 17;if(version_compare($game,'1.17','>='))return 16;return 8; }

    /** @param list<array<string,mixed>> $files @return array<string,mixed>|null */
    private static function primaryFile(array $files): ?array
    { foreach($files as$file)if(!empty($file['primary']))return$file;return$files[0]??null; }
}
