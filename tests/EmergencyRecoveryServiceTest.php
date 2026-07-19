<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\Database;
use Modright\EmergencyRecoveryService;
use Modright\HttpException;
use Modright\SecretStore;
use Modright\SessionService;
use Modright\SystemSettings;
use Modright\UserService;
use PDO;
use PHPUnit\Framework\TestCase;

final class EmergencyRecoveryServiceTest extends TestCase
{
    private function database():PDO{$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);Database::migrate($db);return$db;}

    public function testRecoveryRequiresAdministratorPasswordAndTypedConfirmation():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$id=(new UserService($db))->create('recovery-admin','Recovery Admin','long-enough-password','admin','en_US','recovery-admin@example.test');$service=new EmergencyRecoveryService($db,new SystemSettings($db),new SecretStore());try{$service->apply($id,'wrong','RECOVER SYSTEM','maintenance');self::fail('Wrong password accepted.');}catch(HttpException$e){self::assertSame(403,$e->status);}$this->expectException(\InvalidArgumentException::class);$service->apply($id,'long-enough-password','wrong','maintenance');}

    public function testTwoFactorRecoveryIsAuditedAndInvalidatesEverySession():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$users=new UserService($db);$admin=$users->create('factor-admin','Factor Admin','long-enough-password','admin','en_US','factor-admin@example.test');$user=$users->create('factor-user','Factor User','long-enough-password','user','en_US','factor-user@example.test');$settings=new SystemSettings($db);$settings->setGroup('security',['canonical_url'=>'https://packs.example.test','two_factor_policy'=>'required_everyone']);$settings->setFeature('two_factor',true);$settings->setFeature('passkeys',true);$sessions=new SessionService($db,'session-key');$adminSession=$sessions->create($admin,'Browser','192.0.2.1',['password']);$userSession=$sessions->create($user,'Browser','192.0.2.2',['password']);(new EmergencyRecoveryService($db,$settings,new SecretStore()))->apply($admin,'long-enough-password','RECOVER SYSTEM','two_factor');self::assertSame('disabled',$settings->group('security')['two_factor_policy']);self::assertFalse($settings->feature('two_factor'));self::assertFalse($settings->feature('passkeys'));self::assertNull($sessions->verify($adminSession['token']));self::assertNull($sessions->verify($userSession['token']));self::assertSame('emergency_recovery.applied',$db->query("SELECT action FROM audit_log WHERE action='emergency_recovery.applied'")->fetchColumn());}

    public function testMaintenanceRecoveryPreservesUnrelatedPolicySettings():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$admin=(new UserService($db))->create('policy-admin','Policy Admin','long-enough-password','admin','en_US','policy-admin@example.test');$settings=new SystemSettings($db);$settings->setGroup('maintenance',['enabled'=>true,'starts_at'=>'2026-07-19 10:00:00','ends_at'=>'2026-07-19 12:00:00','pause_jobs'=>false,'pause_cron'=>false,'pause_mail'=>true,'allow_status'=>true]);(new EmergencyRecoveryService($db,$settings,new SecretStore()))->apply($admin,'long-enough-password','RECOVER SYSTEM','maintenance');$maintenance=$settings->group('maintenance');self::assertFalse($maintenance['enabled']);self::assertNull($maintenance['starts_at']);self::assertNull($maintenance['ends_at']);self::assertFalse($maintenance['pause_jobs']);self::assertFalse($maintenance['pause_cron']);self::assertTrue($maintenance['pause_mail']);self::assertTrue($maintenance['allow_status']);}
}
