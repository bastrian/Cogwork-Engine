<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\AuthThrottle;
use Modright\Database;
use Modright\EmailCodeService;
use Modright\GoogleRecaptchaProvider;
use Modright\MailService;
use Modright\UserService;
use Modright\CaptchaVerifier;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthenticationProvidersTest extends TestCase
{
    private function database(): PDO{$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');Database::migrate($db);return$db;}
    public function testRecaptchaValidatesScoreActionHostnameAgeAndFailurePolicy(): void
    { $valid=['success'=>true,'score'=>.8,'action'=>'login','hostname'=>'packs.example.test','challenge_ts'=>gmdate('c')];self::assertTrue((new GoogleRecaptchaProvider('secret',.5,false,fn()=>$valid))->verify('token','login','packs.example.test')['accepted']);foreach([array_replace($valid,['score'=>.2]),array_replace($valid,['action'=>'other']),array_replace($valid,['hostname'=>'evil.test']),array_replace($valid,['challenge_ts'=>gmdate('c',time()-180)]),array_replace($valid,['challenge_ts'=>gmdate('c',time()+90)])]as$response)self::assertFalse((new GoogleRecaptchaProvider('secret',.5,false,fn()=>$response))->verify('token','login','packs.example.test')['accepted']);self::assertFalse((new GoogleRecaptchaProvider('secret',.5,false,fn()=>throw new \RuntimeException()))->verify('token','login','packs.example.test')['accepted']);self::assertTrue((new GoogleRecaptchaProvider('secret',.5,true,fn()=>throw new \RuntimeException()))->verify('token','login','packs.example.test')['accepted']); }
    public function testEmailCodeIsSingleUse(): void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$users=new UserService($db);$id=$users->create('emailcode','Email Code','long-enough-password','user','en_US','code@example.com');$users->setEmail($id,'code@example.com',true);$message=[];$mail=new MailService(['from_address'=>'noreply@example.com'],function($sent)use(&$message){$message=$sent;});$service=new EmailCodeService($db,$mail,new AuthThrottle($db,'throttle-key'),'code-key');$service->send($id,'code@example.com','192.0.2.8');preg_match('/\b(\d{6})\b/',$message['text'],$match);self::assertTrue($service->verify($id,$match[1],'192.0.2.8'));self::assertFalse($service->verify($id,$match[1],'192.0.2.8')); }
    public function testRecaptchaTokenCannotBeReplayed():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$valid=['success'=>true,'score'=>.9,'action'=>'login','hostname'=>'packs.example.test','challenge_ts'=>gmdate('c')];$verifier=new CaptchaVerifier($this->database(),new GoogleRecaptchaProvider('secret',.5,false,fn()=>$valid));self::assertTrue($verifier->verify('one-time-token','login','packs.example.test')['accepted']);$replayed=$verifier->verify('one-time-token','login','packs.example.test');self::assertFalse($replayed['accepted']);self::assertSame('replayed_token',$replayed['error']);}

    public function testSuccessfulAuthenticationClearsOnlyThatSubjectsFailures():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$throttle=new AuthThrottle($db,'throttle-key');for($i=0;$i<5;$i++){$throttle->record('login','alice','192.0.2.1',false);$throttle->record('login','bob','198.51.100.2',false);}self::assertFalse($throttle->check('login','alice','192.0.2.1',5)['allowed']);$throttle->record('login','alice','192.0.2.1',true);self::assertTrue($throttle->check('login','alice','192.0.2.1',5)['allowed']);self::assertFalse($throttle->check('login','bob','198.51.100.2',5)['allowed']);}

    public function testNetworkIdentifiersAreKeyedAndCoarsened():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$throttle=new AuthThrottle($this->database(),'installation-secret');$one=$throttle->pseudonymousNetwork('192.0.2.11');$two=$throttle->pseudonymousNetwork('192.0.2.240');self::assertSame($one,$two);self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$one);self::assertStringNotContainsString('192.0.2',$one);self::assertNotSame($one,(new AuthThrottle($this->database(),'another-secret'))->pseudonymousNetwork('192.0.2.11'));}
}
