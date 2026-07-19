<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\PackRepository;
use PHPUnit\Framework\TestCase;

final class PackValidationTest extends TestCase
{
    public function testValidFabricIndexIsAccepted(): void
    {
        $index = ['game'=>'minecraft','formatVersion'=>1,'name'=>'Test','versionId'=>'1.0.0','summary'=>'','dependencies'=>['minecraft'=>'1.21','fabric-loader'=>'0.16.0'],'files'=>[['path'=>'mods/test.jar','hashes'=>['sha1'=>str_repeat('a',40),'sha512'=>str_repeat('b',128)],'downloads'=>['https://cdn.modrinth.com/data/a/versions/b/test.jar'],'fileSize'=>10]]];
        PackRepository::validateIndex($index);
        self::assertTrue(true);
    }

    public function testIndexRequiresSupportedLoader(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PackRepository::validateIndex(['name'=>'Test','versionId'=>'1','dependencies'=>['minecraft'=>'1.21'],'files'=>[]]);
    }

    public function testFutureLoaderDependencyIsPreserved(): void
    {
        PackRepository::validateIndex(['name'=>'Future','versionId'=>'1','dependencies'=>['minecraft'=>'1.30','future-loader'=>'2'],'files'=>[]]);
        self::assertTrue(true);
    }

    public function testBothFileHashesAreRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PackRepository::validateIndex(['name'=>'Test','versionId'=>'1','dependencies'=>['minecraft'=>'1.21','fabric-loader'=>'1'],'files'=>[['path'=>'mods/a.jar','hashes'=>['sha1'=>'a'],'downloads'=>['https://cdn.modrinth.com/a.jar']]]]);
    }
}
