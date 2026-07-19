<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\Database;
use Modright\PackActivityService;
use Modright\PackRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class PackActivityServiceTest extends TestCase
{
    private function database():PDO{$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('PRAGMA foreign_keys=ON');Database::migrate($db);return$db;}
    public function testRecordsFiltersAndRedactsContext():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$pack=(new PackRepository($db))->create(['formatVersion'=>1,'game'=>'minecraft','versionId'=>'1','name'=>'Test','summary'=>'','files'=>[],'dependencies'=>['minecraft'=>'1.21.1','fabric-loader'=>'0.16.0']]);$service=new PackActivityService($db);$service->record($pack,'build.completed','success','Built',['job_id'=>'job-1','token'=>'must-not-appear'],null);$result=$service->list($pack,1,10,['action'=>'build.completed','result'=>'success','actor'=>'Deleted']);self::assertSame(1,$result['total']);self::assertSame('Deleted User',$result['items'][0]['actor_name']);self::assertStringNotContainsString('must-not-appear',(string)$result['items'][0]['context']);}
    public function testRejectsInvalidActivityKinds():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$this->expectException(\InvalidArgumentException::class);(new PackActivityService($this->database()))->record('none','bad action','success','No');}
}
