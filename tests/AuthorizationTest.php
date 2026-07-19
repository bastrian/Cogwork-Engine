<?php
declare(strict_types=1);
namespace Modright\Tests;
use Modright\Authorization;
use Modright\Database;
use Modright\HttpException;
use Modright\PackRepository;
use Modright\UserService;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthorizationTest extends TestCase
{
    private function database(): PDO
    { $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');Database::migrate($db);return$db; }
    private function pack(PackRepository $packs,string $name): string
    { return$packs->create(['game'=>'minecraft','formatVersion'=>1,'name'=>$name,'versionId'=>'1','summary'=>'','files'=>[],'dependencies'=>['minecraft'=>'1.20.1','forge'=>'47']]); }

    public function testExistingAdministratorAndPacksMigrateWithoutCredentialChanges(): void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$id=Database::id();$hash=password_hash('long-enough-password',PASSWORD_DEFAULT);$db->prepare('INSERT INTO admins (id,username,password_hash,created_at) VALUES (?,?,?,?)')->execute([$id,'legacy',$hash,Database::now()]);Database::migrate($db);$user=(new UserService($db))->authenticate('legacy','long-enough-password');self::assertSame($id,$user['id']);self::assertSame('admin',$user['role']); }

    public function testOwnerAdminAndSharedCapabilitiesAreIsolated(): void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$users=new UserService($db);$adminId=$users->create('adminx','Admin','long-enough-password','admin','en_US','adminx@example.test');$ownerId=$users->create('owner','Owner','long-enough-password','user','en_US','owner@example.test');$viewerId=$users->create('viewer','Viewer','long-enough-password','user','de_DE','viewer@example.test');$outsiderId=$users->create('outside','Outside','long-enough-password','user','en_US','outside@example.test');$packs=new PackRepository($db);$_SESSION['user_id']=$ownerId;$packId=$this->pack($packs,'Owned');unset($_SESSION['user_id']);$owner=new Authorization($db,$users->find($ownerId));$viewer=new Authorization($db,$users->find($viewerId));$outsider=new Authorization($db,$users->find($outsiderId));$admin=new Authorization($db,$users->find($adminId));self::assertTrue($owner->can($packId,'delete'));self::assertTrue($admin->can($packId,'delete'));self::assertFalse($outsider->can($packId,'view'));$owner->grant($packId,$viewerId,'viewer',[]);self::assertTrue($viewer->can($packId,'view'));self::assertFalse($viewer->can($packId,'build'));self::assertCount(1,$viewer->packs());$this->expectException(HttpException::class);$viewer->grant($packId,$outsiderId,'maintainer',[]); }

    public function testDisablingUserInvalidatesSessionVersionAndAuthentication(): void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$users=new UserService($db);$id=$users->create('disableme','Disable Me','long-enough-password','user','en_US','disableme@example.test');$before=$users->find($id);$users->update($id,'Disable Me','user',false,'en_US');$after=$users->find($id);self::assertGreaterThan($before['session_version'],$after['session_version']);self::assertNull($users->authenticate('disableme','long-enough-password')); }

    public function testLastEnabledAdministratorCannotBeDisabled(): void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$users=new UserService($db);$id=$users->create('onlyadmin','Only Admin','long-enough-password','admin','en_US','onlyadmin@example.test');$this->expectException(\InvalidArgumentException::class);$users->update($id,'Only Admin','user',false,'en_US'); }

    public function testPermissionPresetsExposeOnlyTheirDocumentedCapabilities():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$users=new UserService($db);$ownerId=$users->create('presetowner','Owner','long-enough-password','user','en_US','presetowner@example.test');$viewerId=$users->create('presetviewer','Viewer','long-enough-password','user','en_US','presetviewer@example.test');$contributorId=$users->create('presetcontributor','Contributor','long-enough-password','user','en_US','presetcontributor@example.test');$maintainerId=$users->create('presetmaintainer','Maintainer','long-enough-password','user','en_US','presetmaintainer@example.test');$_SESSION['user_id']=$ownerId;$packs=new PackRepository($db);$pack=$this->pack($packs,'Preset Pack');unset($_SESSION['user_id']);$owner=new Authorization($db,$users->find($ownerId));$owner->grant($pack,$viewerId,'viewer',[]);$owner->grant($pack,$contributorId,'contributor',[]);$owner->grant($pack,$maintainerId,'maintainer',[]);foreach(Authorization::CAPABILITIES as$capability){self::assertSame(in_array($capability,Authorization::PRESETS['viewer'],true),(new Authorization($db,$users->find($viewerId)))->can($pack,$capability),'Viewer '.$capability);self::assertSame(in_array($capability,Authorization::PRESETS['contributor'],true),(new Authorization($db,$users->find($contributorId)))->can($pack,$capability),'Contributor '.$capability);self::assertSame(in_array($capability,Authorization::PRESETS['maintainer'],true),(new Authorization($db,$users->find($maintainerId)))->can($pack,$capability),'Maintainer '.$capability);}}
}
