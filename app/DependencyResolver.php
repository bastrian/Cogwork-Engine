<?php

declare(strict_types=1);

namespace Modright;

final class DependencyResolver
{
    /**
     * @param list<string> $selectedProjects
     * @param list<string> $existingProjects
     * @param callable(string):array<string,mixed> $project
     * @param callable(string):list<array<string,mixed>> $versions
     * @return array{files:list<array<string,mixed>>,unresolved:array<string,array<string,mixed>>}
     */
    public function resolve(array $selectedProjects,array $existingProjects,string $game,string $loader,callable $project,callable $versions):array
    {
        $queue=[];foreach(array_values(array_unique($selectedProjects))as$id)$queue[]=['id'=>$id,'required_by'=>[],'root'=>true];
        $seen=array_fill_keys($existingProjects,true);$files=[];$unresolved=[];
        while($queue!==[]){$item=array_shift($queue);$id=(string)$item['id'];if(isset($seen[$id]))continue;$seen[$id]=true;
            $metadata=$project($id);$title=(string)($metadata['title']??$id);$compatible=array_values(array_filter($versions($id),fn(array$v):bool=>$this->compatible($v,$game,$loader)));$version=$compatible[0]??null;
            if(!is_array($version)){
                if(!empty($item['root']))throw new \RuntimeException("{$title} has no compatible Modrinth version for Minecraft {$game} / {$loader}.");
                $unresolved[$id]=['project_id'=>$id,'title'=>$title,'required_by'=>array_values(array_unique($item['required_by'])),'game_version'=>$game,'loader'=>$loader,'acknowledged'=>false,'reason'=>'No compatible Modrinth version is available.'];continue;
            }
            $file=$this->primaryFile($version['files']??[]);if($file===null)throw new \RuntimeException("{$title} has no primary downloadable file.");
            $client=(string)($metadata['client_side']??'unknown');$server=(string)($metadata['server_side']??'unknown');if(!in_array($client,['required','optional','unsupported'],true)||!in_array($server,['required','optional','unsupported'],true))throw new \RuntimeException("{$title} has unknown environment compatibility.");
            $files[]=['path'=>'mods/'.basename((string)$file['filename']),'hashes'=>$file['hashes']??[],'env'=>['client'=>$client,'server'=>$server],'downloads'=>[(string)$file['url']],'fileSize'=>(int)($file['size']??0),'cogwork'=>['project_id'=>$id,'version_id'=>(string)($version['id']??''),'game_versions'=>array_values(array_map('strval',$version['game_versions']??[])),'loaders'=>array_values(array_map('strval',$version['loaders']??[])),'automatically_added'=>empty($item['root']),'required_by'=>array_values(array_unique($item['required_by']))]];
            foreach($version['dependencies']??[]as$dependency){if(($dependency['dependency_type']??'')!=='required'||empty($dependency['project_id']))continue;$dependencyId=(string)$dependency['project_id'];if(isset($seen[$dependencyId]))continue;$queue[]=['id'=>$dependencyId,'required_by'=>array_merge($item['required_by'],[$id]),'root'=>false];}
        }
        return['files'=>$files,'unresolved'=>$unresolved];
    }

    /** @param array<string,mixed> $version */
    public function compatible(array$version,string$game,string$loader):bool
    { $games=array_map('strval',$version['game_versions']??[]);$loaders=array_map('strval',$version['loaders']??[]);return in_array($game,$games,true)&&in_array($loader,$loaders,true); }

    /** @param mixed $files @return array<string,mixed>|null */
    private function primaryFile(mixed$files):?array
    { if(!is_array($files))return null;foreach($files as$file)if(is_array($file)&&!empty($file['primary'])&&!empty($file['url']))return$file;foreach($files as$file)if(is_array($file)&&!empty($file['url']))return$file;return null; }
}
