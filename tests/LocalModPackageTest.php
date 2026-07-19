<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\ArchiveService;
use Modright\PackRepository;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class LocalModPackageTest extends TestCase
{
    public function testLocalModIsEmbeddedButRemovedFromExportIndex(): void
    {
        $root=sys_get_temp_dir().'/modright-local-'.bin2hex(random_bytes(4));mkdir($root.'/mods',0770,true);mkdir($root.'/overrides',0770,true);file_put_contents($root.'/mods/private.jar','private');$file=['path'=>'mods/private.jar','hashes'=>['sha1'=>hash_file('sha1',$root.'/mods/private.jar'),'sha512'=>hash_file('sha512',$root.'/mods/private.jar')],'downloads'=>[],'fileSize'=>7,'local'=>true,'env'=>['client'=>'required','server'=>'required']];$index=['game'=>'minecraft','formatVersion'=>1,'name'=>'Local','versionId'=>'1','dependencies'=>['minecraft'=>'1.21.1','fabric-loader'=>'1'],'files'=>[$file]];
        try{PackRepository::validateIndex($index);$archive=$root.'/pack.mrpack';(new ArchiveService())->build($archive,$index,$root.'/overrides');$zip=new ZipArchive();self::assertTrue($zip->open($archive)===true);$export=json_decode((string)$zip->getFromName('modrinth.index.json'),true);self::assertSame([],$export['files']);self::assertSame('private',$zip->getFromName('overrides/mods/private.jar'));$zip->close();}finally{@unlink($root.'/pack.mrpack');@unlink($root.'/mods/private.jar');@rmdir($root.'/mods');@rmdir($root.'/overrides');@rmdir($root);}
    }
}
