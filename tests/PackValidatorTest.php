<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\PackValidator;
use PHPUnit\Framework\TestCase;

final class PackValidatorTest extends TestCase
{
    public function testReportsDuplicatePathsInvalidHashesAndMissingLocalFiles(): void
    {
        $file=['path'=>'mods/example.jar','hashes'=>['sha1'=>'bad','sha512'=>str_repeat('b',128)],'downloads'=>['https://cdn.modrinth.com/data/a/versions/b/example.jar'],'env'=>['client'=>'required','server'=>'required']];
        $index=['name'=>'Test','versionId'=>'1','dependencies'=>['minecraft'=>'1.21.1','fabric-loader'=>'0.16.0'],'files'=>[$file,$file]];
        $report=(new PackValidator())->validate($index,sys_get_temp_dir().'/missing-pack');
        self::assertGreaterThanOrEqual(2,$report['errors']);
        self::assertGreaterThanOrEqual(2,$report['warnings']);
        self::assertStringContainsString('Duplicate pack path',implode(' ',array_column($report['issues'],'message')));
    }

    public function testValidSynchronizedFilePassesHashChecks(): void
    {
        $root=sys_get_temp_dir().'/modright-validator-'.bin2hex(random_bytes(4));mkdir($root.'/mods',0770,true);file_put_contents($root.'/mods/example.jar','test');
        try{$file=['path'=>'mods/example.jar','hashes'=>['sha1'=>hash_file('sha1',$root.'/mods/example.jar'),'sha512'=>hash_file('sha512',$root.'/mods/example.jar')],'downloads'=>['https://cdn.modrinth.com/data/a/versions/b/example.jar'],'fileSize'=>4,'env'=>['client'=>'required','server'=>'required']];$index=['name'=>'Test','versionId'=>'1','dependencies'=>['minecraft'=>'1.21.1','fabric-loader'=>'0.16.0'],'files'=>[$file]];$report=(new PackValidator())->validate($index,$root);self::assertSame(0,$report['errors']);self::assertNotContains('The synchronized file does not match its SHA1 hash.',array_column($report['issues'],'message'));}finally{@unlink($root.'/mods/example.jar');@rmdir($root.'/mods');@rmdir($root);}
    }
}
