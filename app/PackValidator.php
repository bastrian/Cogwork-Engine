<?php

declare(strict_types=1);

namespace Modright;

final class PackValidator
{
    /** @param array<string,mixed> $index @return array{errors:int,warnings:int,passed:int,issues:list<array{severity:string,check:string,message:string}>} */
    public function validate(array $index, string $packRoot): array
    {
        $issues=[];$passed=0;
        $add=static function(string $severity,string $check,string $message)use(&$issues):void{$issues[]=['severity'=>$severity,'check'=>$check,'message'=>$message];};

        try{PackRepository::validateIndex($index);$passed++;}catch(\Throwable$e){$add('error','Pack index',$e->getMessage());}

        $dependencies=is_array($index['dependencies']??null)?$index['dependencies']:[];
        $loaderKeys=array_intersect(['fabric-loader','forge','neoforge','quilt-loader'],array_keys($dependencies));
        if(empty($dependencies['minecraft']))$add('error','Dependencies','Minecraft version is missing.');else$passed++;
        if(count($loaderKeys)===0)$add('error','Dependencies','No supported mod loader dependency is configured.');
        elseif(count($loaderKeys)>1)$add('error','Dependencies','More than one mod loader dependency is configured: '.implode(', ',$loaderKeys).'.');else{$passed++;$loaderKey=(string)reset($loaderKeys);$loaderName=match($loaderKey){'fabric-loader'=>'fabric','quilt-loader'=>'quilt',default=>$loaderKey};$minecraft=(string)($dependencies['minecraft']??'');$catalog=MODRIGHT_ROOT.'/storage/catalog-loader-v3-'.$loaderName.'-'.preg_replace('/[^A-Za-z0-9._-]/','-',$minecraft).'.json';if(is_file($catalog)){$known=json_decode((string)file_get_contents($catalog),true);if(is_array($known)&&!in_array((string)$dependencies[$loaderKey],$known,true))$add('error','Loader compatibility',$loaderName.' '.$dependencies[$loaderKey].' is not in the saved compatibility catalog for Minecraft '.$minecraft.'.');else$passed++;}else$add('warning','Loader compatibility','No saved compatibility catalog is available for this Minecraft/loader pair. Re-selecting it when creating a pack will populate the catalog.');}

        $paths=[];$downloads=[];$files=is_array($index['files']??null)?$index['files']:[];
        foreach($files as$i=>$file){
            if(!is_array($file)){ $add('error','File #'.($i+1),'The file entry is malformed.');continue; }
            $path=(string)($file['path']??'');$label=$path!==''?$path:'File #'.($i+1);
            try{PackRepository::assertRelativePath($path);}catch(\Throwable){$add('error',$label,'The pack path is unsafe or invalid.');}
            $normalized=strtolower(str_replace('\\','/',$path));
            if(isset($paths[$normalized]))$add('error',$label,'Duplicate pack path; it is also used by '.$paths[$normalized].'.');else$paths[$normalized]=$label;
            foreach(['sha1'=>40,'sha512'=>128]as$algorithm=>$length){$hash=$file['hashes'][$algorithm]??'';if(!is_string($hash)||!preg_match('/^[a-f0-9]{'.$length.'}$/i',$hash))$add('error',$label,strtoupper($algorithm).' hash has an invalid format.');}
            $urls=$file['downloads']??[];
            if(!is_array($urls)||($urls===[]&&empty($file['local'])))$add('error',$label,'No download URL is configured.');
            else foreach($urls as$url){try{if(!is_string($url))throw new \InvalidArgumentException();ModrinthClient::assertUrl($url);if(isset($downloads[$url]))$add('warning',$label,'This download URL is also used by '.$downloads[$url].'.');else$downloads[$url]=$label;}catch(\Throwable){$add('error',$label,'A download URL is invalid or uses an unapproved host.');}}
            $client=$file['env']['client']??null;$server=$file['env']['server']??null;
            if($client===null||$server===null)$add('warning',$label,'Client/server compatibility is incomplete; missing values default to required.');
            if($client==='unsupported'&&$server!=='unsupported')$passed++;
            if($server==='unsupported'&&$client!=='unsupported')$passed++;

            if($path!==''){$local=$packRoot.'/'.$path;if(!is_file($local))$add('warning',$label,'The file has not been synchronized locally.');else{
                $bad=false;foreach(['sha1','sha512']as$algorithm){$expected=$file['hashes'][$algorithm]??'';if(is_string($expected)&&preg_match('/^[a-f0-9]+$/i',$expected)&&!hash_equals(strtolower($expected),hash_file($algorithm,$local))){$add('error',$label,'The synchronized file does not match its '.strtoupper($algorithm).' hash.');$bad=true;}}
                if(isset($file['fileSize'])&&(int)$file['fileSize']!==filesize($local)){$add('warning',$label,'The synchronized file size differs from the index.');$bad=true;}if(!$bad)$passed++;
            }}
        }
        if($files===[])$add('warning','Pack contents','The pack does not contain any mod files yet.');
        $layers=[];foreach(['overrides','server-overrides','client-overrides']as$layer){$directory=$packRoot.'/'.$layer;if(!is_dir($directory))continue;$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS));foreach($iterator as$item){if(!$item->isFile()||$item->isLink())continue;$relative=str_replace('\\','/',substr($item->getPathname(),strlen($directory)+1));if(isset($layers[$relative])&&$layers[$relative]!==$layer)$add('warning','Override conflict: '.$relative,'The file exists in both '.$layers[$relative].' and '.$layer.'; the environment-specific layer wins.');$layers[$relative]=$layer;}}
        if($layers!==[])$passed++;
        return['errors'=>count(array_filter($issues,fn($i)=>$i['severity']==='error')),'warnings'=>count(array_filter($issues,fn($i)=>$i['severity']==='warning')),'passed'=>$passed,'issues'=>$issues];
    }
}
