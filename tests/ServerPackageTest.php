<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\ArchiveService;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ServerPackageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/modright-server-' . bin2hex(random_bytes(5));
        foreach (['mods','overrides/config','server-overrides/config','client-overrides/config'] as $dir) mkdir($this->root.'/'.$dir,0770,true);
        file_put_contents($this->root.'/mods/required.jar','required');
        file_put_contents($this->root.'/mods/optional.jar','optional');
        file_put_contents($this->root.'/mods/client.jar','client');
        file_put_contents($this->root.'/overrides/config/common.txt','common');
        file_put_contents($this->root.'/server-overrides/config/common.txt','server');
        file_put_contents($this->root.'/client-overrides/config/client.txt','client');
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) return;
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as$item)$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());rmdir($this->root);
    }

    public function testServerPackageFiltersModsAndLayersOverrides(): void
    {
        $index=['dependencies'=>['minecraft'=>'1.21','fabric-loader'=>'1'],'files'=>[
            $this->entry('mods/required.jar','required'),$this->entry('mods/optional.jar','optional'),$this->entry('mods/client.jar','unsupported')]];
        $path=$this->root.'/server.zip';(new ArchiveService())->buildServer($path,$index,$this->root,false);
        $zip=new ZipArchive();self::assertTrue($zip->open($path));
        self::assertNotFalse($zip->locateName('mods/required.jar'));
        self::assertFalse($zip->locateName('mods/optional.jar'));
        self::assertFalse($zip->locateName('mods/client.jar'));
        self::assertSame('server',$zip->getFromName('config/common.txt'));
        self::assertFalse($zip->locateName('config/client.txt'));
        self::assertNotFalse($zip->locateName('README.txt'));$zip->close();
    }

    /** @return array<string,mixed> */
    private function entry(string $path,string $server): array
    {
        $file=$this->root.'/'.$path;return ['path'=>$path,'env'=>['server'=>$server,'client'=>'required'],'hashes'=>['sha1'=>hash_file('sha1',$file),'sha512'=>hash_file('sha512',$file)],'downloads'=>['https://cdn.modrinth.com/'.$path],'fileSize'=>filesize($file)];
    }
}
