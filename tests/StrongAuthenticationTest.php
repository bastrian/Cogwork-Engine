<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\Database;
use Modright\RecoveryCodeService;
use Modright\SecurityEventService;
use Modright\TotpService;
use Modright\UserService;
use PDO;
use PHPUnit\Framework\TestCase;

final class StrongAuthenticationTest extends TestCase
{
    public function testTotpMatchesRfc6238Sha1Vector(): void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();
        $totp=new TotpService($this->database(),'application-key-at-least-sixteen');
        self::assertSame('287082',$totp->codeForTesting('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',59));
    }

    private function database(): PDO
    { $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');Database::migrate($db);return$db; }

    public function testTotpEnrollmentVerificationAndReplayProtection(): void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$id=(new UserService($db))->create('totpuser','TOTP','long-enough-password','user','en_US','totpuser@example.test');$totp=new TotpService($db,'application-key-at-least-sixteen');$enrollment=$totp->begin($id,'totpuser');self::assertStringStartsWith('otpauth://totp/',$enrollment['uri']);self::assertSame(0,(int)$db->query('SELECT COUNT(*) FROM user_totp')->fetchColumn());$now=1710000000;$code=$totp->codeForTesting($enrollment['secret'],$now);self::assertTrue($totp->confirmEnrollment($id,$enrollment['sealed'],$code,$now));self::assertFalse($totp->verify($id,$code,$now));self::assertTrue($totp->verify($id,$totp->codeForTesting($enrollment['secret'],$now+30),$now+30));self::assertStringNotContainsString($enrollment['secret'],(string)$db->query('SELECT secret_ciphertext FROM user_totp')->fetchColumn()); }

    public function testRecoveryCodesAreSingleUseAndReplaced(): void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$id=(new UserService($db))->create('codesuser','Codes','long-enough-password','user','en_US','codesuser@example.test');$codes=new RecoveryCodeService($db);$first=$codes->regenerate($id);self::assertCount(10,$first);self::assertSame(10,$codes->remaining($id));self::assertTrue($codes->consume($id,strtolower(str_replace('-',' ',$first[0]))));self::assertFalse($codes->consume($id,$first[0]));self::assertSame(9,$codes->remaining($id));$second=$codes->regenerate($id,5);self::assertCount(5,$second);self::assertFalse($codes->consume($id,$first[1])); }

    public function testStartingTotpReplacementKeepsExistingFactorUntilConfirmation():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$id=(new UserService($db))->create('replacefactor','Replace','long-enough-password','user','en_US','replacefactor@example.test');$totp=new TotpService($db,'application-key-at-least-sixteen');$now=1710000000;$first=$totp->begin($id,'replacefactor');self::assertTrue($totp->confirmEnrollment($id,$first['sealed'],$totp->codeForTesting($first['secret'],$now),$now));$second=$totp->begin($id,'replacefactor');self::assertSame(1,(int)$db->query('SELECT COUNT(*) FROM user_totp')->fetchColumn());self::assertFalse($totp->confirmEnrollment($id,$second['sealed'],'000000',$now+30));self::assertTrue($totp->verify($id,$totp->codeForTesting($first['secret'],$now+30),$now+30));}

    public function testTotpAcceptsOnlyDocumentedOneStepClockSkew():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$id=(new UserService($db))->create('skewuser','Skew','long-enough-password','user','en_US','skew@example.test');$totp=new TotpService($db,'application-key-at-least-sixteen');$now=1710000000;$enrollment=$totp->begin($id,'skewuser');self::assertTrue($totp->confirmEnrollment($id,$enrollment['sealed'],$totp->codeForTesting($enrollment['secret'],$now),$now));self::assertTrue($totp->verify($id,$totp->codeForTesting($enrollment['secret'],$now+30),$now));self::assertFalse($totp->verify($id,$totp->codeForTesting($enrollment['secret'],$now+90),$now));}

    public function testSecurityEventsRejectSensitiveContext(): void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$events=new SecurityEventService($db);$events->record(null,'login.failed','warning',['reason'=>'bad_credentials','submitted_token'=>'never-store','ip'=>'192.0.2.1']);$context=json_decode($events->recent()[0]['context'],true);self::assertSame(['reason'=>'bad_credentials'],$context); }
}
