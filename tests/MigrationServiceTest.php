<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\Database;
use Modright\MigrationService;
use Modright\PackRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class MigrationServiceTest extends TestCase
{
    public function testClassifiesPublishedLocalAndUnavailableMods(): void
    {
        $remote=['path'=>'mods/a.jar','downloads'=>['https://cdn.modrinth.com/data/project/versions/old/a.jar']];
        $version=['id'=>'new','version_number'=>'2.0','version_type'=>'release','date_published'=>'2026-01-01','files'=>[['primary'=>true,'filename'=>'a-2.jar','url'=>'https://cdn.modrinth.com/data/project/versions/new/a-2.jar','hashes'=>['sha1'=>'a','sha512'=>'b'],'size'=>10]],'dependencies'=>[]];
        self::assertSame('direct',MigrationService::classify($remote,[$version])['classification']);
        self::assertSame('incompatible',MigrationService::classify($remote,[])['classification']);
        self::assertSame('unknown',MigrationService::classify(['local'=>true,'downloads'=>[]],[])['classification']);
        $version['version_type']='beta';self::assertSame('incompatible',MigrationService::classify($remote,[$version],false)['classification']);self::assertSame('direct',MigrationService::classify($remote,[$version],true)['classification']);
    }

    public function testEssentialBlockersDominateRecommendationScore(): void
    {
        $rows=[['file_index'=>0,'classification'=>'direct','evidence'=>['environment'=>['client'=>'required','server'=>'unsupported']]],['file_index'=>1,'classification'=>'incompatible','evidence'=>['environment'=>['client'=>'optional','server'=>'required']]]];
        $normal=MigrationService::summarize($rows);$essential=MigrationService::summarize($rows,[1=>'essential']);
        self::assertSame(0,$normal['essential_blocked']);self::assertSame(1,$essential['essential_blocked']);self::assertLessThan($normal['score'],$essential['score']);self::assertSame(1,$normal['environments']['client_only']);self::assertSame(1,$normal['environments']['optional']);
    }

    public function testApplyCreatesCopyAndDoesNotMutateSource(): void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped('pdo_sqlite unavailable.');$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys = ON');Database::migrate($db);$packs=new PackRepository($db);$sourceIndex=['game'=>'minecraft','formatVersion'=>1,'name'=>'Old Pack','versionId'=>'1.0','summary'=>'','dependencies'=>['minecraft'=>'1.19.2','forge'=>'43.0.0'],'files'=>[['path'=>'mods/a.jar','downloads'=>['https://cdn.modrinth.com/data/project/versions/old/a.jar'],'hashes'=>['sha1'=>str_repeat('a',40),'sha512'=>str_repeat('b',128)],'fileSize'=>1]]];$sourceId=$packs->create($sourceIndex);$service=new MigrationService($db,$packs);
        try{$scan=$service->createScan($sourceId,[['game'=>'1.20.1','loader'=>'fabric','loader_version'=>'0.16.0']]);$classified=MigrationService::classify($sourceIndex['files'][0],[['id'=>'new','version_number'=>'2.0','version_type'=>'release','files'=>[['primary'=>true,'filename'=>'a2.jar','url'=>'https://cdn.modrinth.com/data/project/versions/new/a2.jar','hashes'=>['sha1'=>str_repeat('c',40),'sha512'=>str_repeat('d',128)],'size'=>2]]]]);$service->saveResult($scan,'1.20.1','fabric','0.16.0','project',0,$classified);$service->complete($scan);$targetId=$service->apply($scan,'1.20.1','fabric',[0]);$source=$packs->find($sourceId);$target=$packs->find($targetId);self::assertSame('1.19.2',$source['game_version']);self::assertSame($sourceIndex,$source['index']);self::assertSame('1.20.1',$target['game_version']);self::assertSame('fabric',$target['loader']);self::assertSame('mods/a2.jar',$target['index']['files'][0]['path']);self::assertSame(1,(int)$db->query('SELECT COUNT(*) FROM migration_manifests')->fetchColumn());$packs->delete($targetId);}finally{$packs->delete($sourceId);}
    }

    public function testReplacementMappingsAreExplicitAndEditable(): void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped('pdo_sqlite unavailable.');$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);Database::migrate($db);$service=new MigrationService($db,new PackRepository($db));$service->saveReplacement('forge-project','fabric','fabric-project','high','Same feature set');$mapping=$service->replacementFor('forge-project','fabric');self::assertSame('fabric-project',$mapping['replacement_project_id']);self::assertSame('high',$mapping['confidence']);$service->deleteReplacement('forge-project','fabric');self::assertNull($service->replacementFor('forge-project','fabric'));
    }
}
