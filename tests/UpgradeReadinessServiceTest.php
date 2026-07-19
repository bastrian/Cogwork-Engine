<?php
declare(strict_types=1);
namespace Modright\Tests;
use Modright\Database;use Modright\SystemSettings;use Modright\UpgradeReadinessService;use PDO;use PHPUnit\Framework\TestCase;
final class UpgradeReadinessServiceTest extends TestCase
{
    public function testReportIsRedactedAndWarnsWithoutBackupOrChecksum():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);Database::migrate($db);$update=['release'=>['version'=>'1.2.3','assets'=>[['name'=>'cogwork-engine-1.2.3.zip']]]];$report=(new UpgradeReadinessService($db,new SystemSettings($db)))->report(['HTTPS'=>'on'],$update);self::assertSame('1.2.3',$report['target_version']);self::assertFalse($report['release_assets']['sha256']);self::assertNotEmpty($report['warnings']);$json=strtolower(json_encode($report));self::assertStringNotContainsString('password_hash',$json);self::assertStringNotContainsString('app_key',$json);self::assertStringNotContainsString('database dsn',$json);}

    public function testOnlySuccessfulDownloadedBackupCountsAsFreshUpgradeBackup():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);Database::migrate($db);$pack=(new \Modright\PackRepository($db))->create(['formatVersion'=>1,'game'=>'minecraft','versionId'=>'1','name'=>'Snapshot Only','summary'=>'','files'=>[],'dependencies'=>['minecraft'=>'1.21.1','fabric-loader'=>'0.16']]);$db->prepare('INSERT INTO backups (id,pack_id,version_id,path,created_at) VALUES (?,?,?,?,?)')->execute([Database::id(),$pack,'1',MODRIGHT_ROOT.'/storage/backups/test-index.json',Database::now()]);$service=new UpgradeReadinessService($db,new SystemSettings($db));self::assertFalse($service->report()['backup']['fresh']);$db->prepare('INSERT INTO audit_log (id,action,context,created_at) VALUES (?,?,?,?)')->execute([Database::id(),'backup.exported','{"bytes":123}',Database::now()]);self::assertTrue($service->report()['backup']['fresh']);}
}
