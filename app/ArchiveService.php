<?php

declare(strict_types=1);

namespace Modright;

use ZipArchive;

final class ArchiveService
{
    private const MAX_FILES = 10000;
    private const MAX_UNCOMPRESSED = 2147483648;

    /** @return array<string, mixed> */
    public function readIndex(string $archive, ?string $extractOverridesTo = null): array
    {
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) throw new \InvalidArgumentException('The uploaded file is not a valid ZIP archive.');
        try {
            if ($zip->numFiles > self::MAX_FILES) throw new \InvalidArgumentException('The archive contains too many files.');
            $total = 0;
            $index = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!$stat) continue;
                $name = str_replace('\\', '/', $stat['name']);
                self::assertEntry($name);
                $total += (int) $stat['size'];
                if ($total > self::MAX_UNCOMPRESSED) throw new \InvalidArgumentException('The archive expands beyond the safety limit.');
                if ($name === 'modrinth.index.json') {
                    if ((int) $stat['size'] > 10 * 1024 * 1024) throw new \InvalidArgumentException('The pack index is too large.');
                    $index = json_decode((string) $zip->getFromIndex($i), true, 512, JSON_THROW_ON_ERROR);
                }
                $layer = null;
                foreach (['overrides', 'server-overrides', 'client-overrides'] as $candidate) {
                    if (str_starts_with($name, $candidate . '/')) { $layer = $candidate; break; }
                }
                if ($extractOverridesTo !== null && $layer !== null && !str_ends_with($name, '/')) {
                    $relative = substr($name, strlen($layer) + 1);
                    $destination = dirname($extractOverridesTo) . '/' . $layer . '/' . $relative;
                    $directory = dirname($destination);
                    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) throw new \RuntimeException('Cannot create overrides directory.');
                    $source = $zip->getStream($stat['name']);
                    $target = fopen($destination, 'wb');
                    if (!$source || !$target) throw new \RuntimeException('Cannot extract archive entry.');
                    stream_copy_to_stream($source, $target, (int) $stat['size'] + 1);
                    fclose($source); fclose($target);
                }
            }
            if (!is_array($index)) throw new \InvalidArgumentException('Archive is missing modrinth.index.json.');
            PackRepository::validateIndex($index);
            return $index;
        } finally {
            $zip->close();
        }
    }

    /** @param array<string, mixed> $index */
    public function build(string $destination, array $index, string $overrides, string $profile='standard'): void
    {
        $zip = new ZipArchive();
        if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('Cannot create package.');
        try {
            $base = dirname($overrides);
            PackProfile::definition($profile);$export=$index;$export['files']=[];foreach($index['files']as$entry){if(!PackProfile::includes($entry,$profile))continue;if(!empty($entry['local'])){$source=$base.'/'.$entry['path'];if(!is_file($source))throw new \RuntimeException('Local mod is missing: '.$entry['path']);foreach(['sha1','sha512']as$algorithm)if(!hash_equals(strtolower((string)$entry['hashes'][$algorithm]),hash_file($algorithm,$source)))throw new \RuntimeException('Local mod hash changed: '.$entry['path']);$zip->addFile($source,'overrides/'.$entry['path']);}else$export['files'][]=$entry;}
            $zip->addFromString('modrinth.index.json', PackRepository::encode($export));
            foreach (['overrides', 'server-overrides', 'client-overrides'] as $layer) {
                $this->addDirectory($zip, $base . '/' . $layer, $layer . '/');
            }
        } finally {
            $zip->close();
        }
    }

    /** @param array<string, mixed> $index */
    public function buildServer(string $destination, array $index, string $packRoot, bool $includeOptional, array $options=[]): void
    {
        $zip = new ZipArchive();
        if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('Cannot create server package.');
        try {
            $exclusions=array_map(fn($path)=>str_replace('\\','/',(string)$path),$options['exclusions']??[]);foreach ($index['files'] as $entry) {
                $server = $entry['env']['server'] ?? 'required';
                if ($server === 'unsupported' || ($server === 'optional' && !$includeOptional) || in_array($entry['path'],$exclusions,true)) continue;
                PackRepository::assertRelativePath($entry['path']);
                $source = $packRoot . '/' . $entry['path'];
                if (!is_file($source)) throw new \RuntimeException('Server file is not synchronized: ' . $entry['path']);
                foreach (['sha1', 'sha512'] as $hash) {
                    if (!hash_equals(strtolower($entry['hashes'][$hash]), hash_file($hash, $source))) throw new \RuntimeException('Hash mismatch for ' . $entry['path']);
                }
                $zip->addFile($source, $entry['path']);
            }
            if(($options['include_common_overrides']??true))$this->addDirectory($zip, $packRoot . '/overrides', '');
            if(($options['include_server_overrides']??true))$this->addDirectory($zip, $packRoot . '/server-overrides', '', true);
            $loader = $this->loaderDescription($index['dependencies']);
            $java=(int)($options['java_version']??17);$min=(int)($options['memory_min']??1024);$max=(int)($options['memory_max']??4096);$readme = "SERVER PACK\n===========\n\nMinecraft: " . $index['dependencies']['minecraft'] . "\nLoader: {$loader}\nRequired Java: {$java}\nMemory: {$min}-{$max} MB\n\n1. Install the listed Minecraft server and loader.\n2. Confirm eula.txt reflects your own EULA decision.\n3. Follow the loader's bootstrap instructions if it creates run.sh or run.bat.\n4. Run the appropriate start script.\n";
            $zip->addFromString('README.txt', $readme);
            $zip->addFromString('eula.txt',"# Set to true only after accepting https://aka.ms/MinecraftEULA\neula=".(!empty($options['eula_accepted'])?'true':'false')."\n");$properties=str_replace("\r\n","\n",(string)($options['server_properties']??''));if($properties!=='')$zip->addFromString('server.properties',rtrim($properties)."\n");$replace=['{MIN}'=>(string)$min,'{MAX}'=>(string)$max];$linux=strtr((string)($options['linux_command']??'java -Xms{MIN}M -Xmx{MAX}M -jar server.jar nogui'),$replace);$windows=strtr((string)($options['windows_command']??'java -Xms{MIN}M -Xmx{MAX}M -jar server.jar nogui'),$replace);
            $zip->addFromString('start.sh',"#!/bin/sh\nset -eu\nif [ -x ./run.sh ]; then exec ./run.sh nogui; fi\nexec ".$linux."\n");
            $zip->addFromString('start.bat',"@echo off\r\nif exist run.bat (\r\n  call run.bat nogui\r\n  exit /b %errorlevel%\r\n)\r\n".$windows."\r\n");
        } finally {
            $zip->close();
        }
    }

    /** @return array{entries:int,overrides:int,server_overrides:int,client_overrides:int,conflicts:list<string>} */
    public function inspect(string $archive): array
    { $zip=new ZipArchive();if($zip->open($archive)!==true)throw new \InvalidArgumentException('The uploaded file is not a valid ZIP archive.');$counts=['entries'=>$zip->numFiles,'overrides'=>0,'server_overrides'=>0,'client_overrides'=>0,'conflicts'=>[]];$seen=[];try{for($i=0;$i<$zip->numFiles;$i++){$stat=$zip->statIndex($i);if(!$stat)continue;$name=str_replace('\\','/',$stat['name']);self::assertEntry($name);foreach(['overrides','server-overrides','client-overrides']as$layer){if(str_starts_with($name,$layer.'/')&&!str_ends_with($name,'/')){$key=str_replace('-','_',$layer);$counts[$key]++;$relative=substr($name,strlen($layer)+1);if(isset($seen[$relative])&&$seen[$relative]!==$layer)$counts['conflicts'][]=$relative;$seen[$relative]=$layer;break;}}}return$counts;}finally{$zip->close();} }

    private function addDirectory(ZipArchive $zip, string $directory, string $prefix, bool $replace = false): void
    {
        if (!is_dir($directory)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) continue;
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
            $name = $prefix . $relative; self::assertEntry($name);
            if ($replace && $zip->locateName($name) !== false) $zip->deleteName($name);
            $zip->addFile($file->getPathname(), $name);
        }
    }

    /** @param array<string,mixed> $dependencies */
    private function loaderDescription(array $dependencies): string
    {
        foreach ($dependencies as $name => $version) if ($name !== 'minecraft') return $name . ' ' . $version;
        return 'not specified';
    }

    public static function assertEntry(string $name): void
    {
        if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:/#', $name)) throw new \InvalidArgumentException('Unsafe archive path.');
        foreach (explode('/', $name) as $segment) {
            if ($segment === '..') throw new \InvalidArgumentException('Unsafe archive path.');
        }
    }
}
