<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\Database;
use Modright\SystemSettings;
use Modright\UpdateService;
use PDO;
use PHPUnit\Framework\TestCase;

final class UpdateServiceTest extends TestCase
{
    private function database(): PDO
    { $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);Database::migrate($db);return$db; }

    public function testSelectsNewestAllowedReleaseAndSafeAssets(): void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$settings=new SystemSettings($db);$settings->setGroup('updates',['channel'=>'prerelease']);$payload=[['tag_name'=>'v0.3.0','name'=>'Preview','html_url'=>'https://github.com/bastrian/Cogwork-Engine/releases/tag/v0.3.0','draft'=>false,'prerelease'=>true,'published_at'=>'2026-08-01T00:00:00Z','assets'=>[['name'=>'cogwork-engine-0.3.0.zip','browser_download_url'=>'https://github.com/bastrian/Cogwork-Engine/releases/download/v0.3.0/file.zip','size'=>42],['name'=>'evil.exe','browser_download_url'=>'https://evil.test/a']]],['tag_name'=>'v0.2.0','draft'=>false,'prerelease'=>true,'assets'=>[]]];$service=new UpdateService($db,$settings,fn()=>['status'=>200,'body'=>json_encode($payload,JSON_THROW_ON_ERROR),'etag'=>'"abc"','rate_remaining'=>'42','rate_reset'=>'123']);$result=$service->check(true);self::assertSame('0.3.0',$result['release']['version']);self::assertTrue($result['update_available']);self::assertCount(1,$result['release']['assets']);self::assertSame('42',$settings->get('system.update_rate')['remaining']);
    }

    public function testStableChannelIgnoresPrereleasesAndUsesStaleCacheOnFailure(): void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$settings=new SystemSettings($db);$settings->setGroup('updates',['channel'=>'stable']);$payload=[['tag_name'=>'v9.0.0-beta.1','draft'=>false,'prerelease'=>true,'assets'=>[]],['tag_name'=>'v1.0.0','name'=>'Stable','html_url'=>'https://github.com/bastrian/Cogwork-Engine/releases/tag/v1.0.0','draft'=>false,'prerelease'=>false,'assets'=>[]]];$ok=new UpdateService($db,$settings,fn()=>['status'=>200,'body'=>json_encode($payload,JSON_THROW_ON_ERROR)]);self::assertSame('1.0.0',$ok->check(true)['release']['version']);$failed=new UpdateService($db,$settings,fn()=>throw new \RuntimeException('offline'));$result=$failed->check(true);self::assertSame('stale',$result['source']);self::assertSame('1.0.0',$result['release']['version']);
    }

    public function testDisabledCheckerMakesNoRequest(): void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$settings=new SystemSettings($db);$settings->setFeature('update_checks',false);$called=false;$service=new UpdateService($db,$settings,function()use(&$called){$called=true;return[];});self::assertSame('disabled',$service->check(true)['status']);self::assertFalse($called);
    }

    public function testDashboardReadDoesNotPerformInitialNetworkCheck():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$service=new UpdateService($db,new SystemSettings($db));$started=microtime(true);$status=$service->check();self::assertSame('not_checked',$status['status']);self::assertNull($status['checked_at']);self::assertLessThan(.1,microtime(true)-$started);}

    public function testConditionalRequestHandlesNotModified():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$settings=new SystemSettings($db);$payload=[['tag_name'=>'v0.2.0','draft'=>false,'prerelease'=>false,'assets'=>[]]];$first=new UpdateService($db,$settings,fn()=>['status'=>200,'body'=>json_encode($payload),'etag'=>'"etag"','last_modified'=>'today']);$first->check(true);$headers=[];$second=new UpdateService($db,$settings,function($url,$sent)use(&$headers){$headers=$sent;return['status'=>304];});$result=$second->check(true);self::assertSame('not_modified',$result['source']);self::assertSame('"etag"',$headers['If-None-Match']);self::assertSame('today',$headers['If-Modified-Since']);}

    public function testMalformedAndRateLimitedResponsesUseSafeFallbacks():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$settings=new SystemSettings($db);$malformed=(new UpdateService($db,$settings,fn()=>['status'=>200,'body'=>'{']))->check(true);self::assertSame('unavailable',$malformed['status']);self::assertIsString($settings->get('system.update_last_failure'));$limited=(new UpdateService($db,$settings,fn()=>['status'=>429,'body'=>'too many requests']))->check(true);self::assertSame('unavailable',$limited['status']);self::assertStringContainsString('429',$limited['error']);}

    public function testReleaseNotesArePlainAndExpectedAssetsAreReported():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$settings=new SystemSettings($db);$payload=[['tag_name'=>'v0.2.0','name'=>'Release','body'=>'<script>alert(1)</script><b>Upgrade notes</b>','html_url'=>'https://github.com/bastrian/Cogwork-Engine/releases/tag/v0.2.0','draft'=>false,'prerelease'=>false,'assets'=>[['name'=>'cogwork-engine-0.2.0.zip','browser_download_url'=>'https://github.com/bastrian/Cogwork-Engine/releases/download/v0.2.0/a.zip','digest'=>'sha256:abc'],['name'=>'cogwork-engine-0.2.0.zip.sha256','browser_download_url'=>'https://github.com/bastrian/Cogwork-Engine/releases/download/v0.2.0/a.sha256']]]];$result=(new UpdateService($db,$settings,fn()=>['status'=>200,'body'=>json_encode($payload)]))->check(true);self::assertStringNotContainsString('<script>',$result['release']['notes']);self::assertTrue($result['release']['asset_status']['zip']);self::assertTrue($result['release']['asset_status']['sha256']);self::assertTrue($result['release']['asset_status']['github_digest']);}

    public function testStructuredRequirementsAreValidatedAndHiddenFromNotes():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$settings=new SystemSettings($db);$body='Upgrade safely. <!-- cogwork-requirements: {"php":"99.0","extensions":["curl","missing_extension","../bad"]} -->';$payload=[['tag_name'=>'v0.2.0','name'=>'Requirements','body'=>$body,'html_url'=>'https://github.com/bastrian/Cogwork-Engine/releases/tag/v0.2.0','draft'=>false,'prerelease'=>false,'assets'=>[]],['tag_name'=>'not-a-version','draft'=>false,'prerelease'=>false]];$update=(new UpdateService($db,$settings,fn()=>['status'=>200,'body'=>json_encode($payload)]))->check(true);self::assertSame(['php'=>'99.0','extensions'=>['curl','missing_extension']],$update['release']['requirements']);self::assertSame('Upgrade safely.',$update['release']['notes']);$report=(new \Modright\UpgradeReadinessService($db,$settings))->report([],$update);self::assertStringContainsString('PHP 99.0',implode(' ',$report['blockers']));self::assertStringContainsString('missing_extension',implode(' ',$report['blockers']));}

    public function testRetryAfterPreventsAnotherProviderRequest():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$settings=new SystemSettings($db);$settings->set('system.update_rate',['retry_after'=>'120','checked_at'=>Database::now()]);$called=false;$result=(new UpdateService($db,$settings,function()use(&$called){$called=true;return[];}))->check(true);self::assertSame('rate_limited',$result['status']);self::assertFalse($called);}

    public function testEqualAndOlderReleasesAreNeverReportedAsUpdates():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();foreach(['0.2.0','0.1.9']as$version){$db=$this->database();$settings=new SystemSettings($db);$payload=[['tag_name'=>'v'.$version,'draft'=>false,'prerelease'=>false,'assets'=>[]]];$result=(new UpdateService($db,$settings,fn()=>['status'=>200,'body'=>json_encode($payload,JSON_THROW_ON_ERROR)]))->check(true);self::assertSame($version,$result['release']['version']);self::assertFalse($result['update_available']);}}
}
