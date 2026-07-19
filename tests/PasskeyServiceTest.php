<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\Database;
use Modright\PasskeyService;
use Modright\UserService;
use PDO;
use PHPUnit\Framework\TestCase;

final class PasskeyServiceTest extends TestCase
{
    private function database(): PDO{$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');Database::migrate($db);return$db;}
    public function testRegistrationOptionsRequireUserVerificationAndBoundChallenge(): void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$users=new UserService($db);$id=$users->create('passkeyuser','Passkey User','long-enough-password','user','en_US','passkey@example.com');$service=new PasskeyService($db,'packs.example.test','https://packs.example.test');$request=$service->beginRegistration($users->find($id));self::assertSame('packs.example.test',$request['publicKey']['rp']['id']);self::assertSame('required',$request['publicKey']['authenticatorSelection']['userVerification']);self::assertSame('preferred',$request['publicKey']['authenticatorSelection']['residentKey']);$row=$db->query("SELECT * FROM auth_challenges WHERE purpose='passkey_register'")->fetch();self::assertSame($request['id'],$row['id']);self::assertNotSame('',$row['challenge_hash']);self::assertNull($row['used_at']); }
    public function testPasskeysRejectInsecureOrMismatchedOrigins(): void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$this->expectException(\InvalidArgumentException::class);new PasskeyService($this->database(),'packs.example.test','http://packs.example.test'); }

    public function testPasskeyChallengeIsSessionBoundAndExpires():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$users=new UserService($db);$id=$users->create('boundpasskey','Bound','long-enough-password','user','en_US','bound@example.test');$begin=new PasskeyService($db,'packs.example.test','https://packs.example.test','session-a');$challenge=$begin->beginRegistration($users->find($id));try{(new PasskeyService($db,'packs.example.test','https://packs.example.test','session-b'))->finishRegistration($id,$challenge['id'],'{}','Wrong session');self::fail('A challenge crossed session boundaries.');}catch(\InvalidArgumentException$e){self::assertStringContainsString('session',$e->getMessage());}$db->prepare('UPDATE auth_challenges SET expires_at=? WHERE id=?')->execute(['2000-01-01T00:00:00+00:00',$challenge['id']]);$this->expectException(\InvalidArgumentException::class);$this->expectExceptionMessage('invalid or expired');$begin->finishRegistration($id,$challenge['id'],'{}','Expired');}
}
