<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\AnnouncementService;
use Modright\AuditService;
use Modright\Database;
use Modright\MaintenanceService;
use Modright\MailService;
use Modright\NotificationService;
use Modright\SystemSettings;
use Modright\UserService;
use PDO;
use PHPUnit\Framework\TestCase;

final class SystemFoundationTest extends TestCase
{
    private function database(): PDO
    { $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');Database::migrate($db);return$db; }

    public function testAuditRedactsSecrets(): void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$audit=new AuditService($db);$audit->record('security.test',['token'=>'never-store','nested'=>['password'=>'also-secret'],'safe'=>'yes']);$context=json_decode($audit->recent(1)[0]['context'],true);self::assertSame('[redacted]',$context['token']);self::assertSame('[redacted]',$context['nested']['password']);self::assertSame('yes',$context['safe']);
    }

    public function testAuditSearchCombinesAndFiltersSecurityEvents():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$audit=new AuditService($db);$audit->record('pack.changed',['pack_id'=>'pack-1'],'user-1');(new \Modright\SecurityEventService($db))->record(null,'login.failed','warning',['reason'=>'invalid']);self::assertCount(1,$audit->search(['pack'=>'pack-1','source'=>'audit']));$security=$audit->search(['action'=>'login','source'=>'security']);self::assertCount(1,$security);self::assertSame('security',$security[0]['source']);}

    public function testNotificationOwnershipAndLocalTargets(): void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$users=new UserService($db);$one=$users->create('notifyone','One','long-enough-password','user','en_US','notifyone@example.test');$two=$users->create('notifytwo','Two','long-enough-password','user','en_US','notifytwo@example.test');$service=new NotificationService($db);$id=$service->send($one,'security','warning','Notice','Review this','/index.php?route=account');self::assertSame(1,$service->unreadCount($one));self::assertSame(0,$service->unreadCount($two));$service->markRead($id,$two);self::assertSame(1,$service->unreadCount($one));$service->markRead($id,$one);self::assertSame(0,$service->unreadCount($one));
    }

    public function testDisabledNotificationsAreEnforcedAtServiceBoundary():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$users=new UserService($db);$id=$users->create('notifications-off','Notifications Off','long-enough-password','user','en_US','notifications-off@example.test');$settings=new SystemSettings($db);$settings->setFeature('notifications',false);$service=new NotificationService($db);self::assertSame('',$service->send($id,'security','critical','Security event','Review your account'));self::assertSame(0,$service->broadcast('updates','info','Update','Available','',[$id]));self::assertSame(0,(int)$db->query('SELECT COUNT(*) FROM notifications')->fetchColumn());}

    public function testNotificationHidesTargetsAfterAuthorizationIsLost():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$users=new UserService($db);$owner=$users->create('targetowner','Owner','long-enough-password','user','en_US','owner@example.test');$viewer=$users->create('targetviewer','Viewer','long-enough-password','user','en_US','viewer@example.test');$_SESSION['user_id']=$owner;$pack=(new \Modright\PackRepository($db))->create(['formatVersion'=>1,'game'=>'minecraft','versionId'=>'1','name'=>'Target Pack','summary'=>'','files'=>[],'dependencies'=>['minecraft'=>'1.21.1','fabric-loader'=>'0.16.0']]);$auth=new \Modright\Authorization($db,$users->find($owner));$auth->grant($pack,$viewer,'viewer',[]);$service=new NotificationService($db);$service->send($viewer,'permissions','info','Pack shared','Open it','/index.php?route=packs%2Fview&id='.$pack);self::assertNotSame('',$service->forUser($viewer)[0]['target_url']);$auth->revoke($pack,$viewer);self::assertSame('',$service->forUser($viewer)[0]['target_url']);unset($_SESSION['user_id']);}

    public function testScheduledMaintenanceState(): void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$settings=new SystemSettings($this->database());$settings->setGroup('maintenance',['enabled'=>true,'starts_at'=>'2026-01-01T00:00:00+00:00','ends_at'=>'2026-01-01T01:00:00+00:00']);$service=new MaintenanceService($settings);self::assertTrue($service->state(strtotime('2026-01-01T00:30:00Z'))['active']);self::assertFalse($service->state(strtotime('2026-01-01T02:00:00Z'))['active']);
    }

    public function testMaintenanceCanPauseNewJobs():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$settings=new SystemSettings($db);$settings->setGroup('maintenance',['enabled'=>true,'pause_jobs'=>true]);$this->expectException(\Modright\HttpException::class);(new \Modright\JobService($db,new \Modright\PackRepository($db)))->create('import',null,['version_id'=>'example']);}

    public function testMaintenanceSeparatesNewAndRunningJobPolicies():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$settings=new SystemSettings($db);$jobs=new \Modright\JobService($db,new \Modright\PackRepository($db),new \Modright\ModrinthClient(),new \Modright\ArchiveService(),null,$settings);$id=$jobs->create('retention_cleanup',null,['estimated_records'=>0]);$settings->setGroup('maintenance',['enabled'=>true,'pause_new_jobs'=>false,'pause_running_jobs'=>true]);$created=$jobs->create('retention_cleanup',null,['estimated_records'=>0]);self::assertSame('retention_cleanup',$jobs->find($created)['type']);self::assertSame('queued',$jobs->step($id)['status']);$settings->setGroup('maintenance',['pause_new_jobs'=>true,'pause_running_jobs'=>false]);self::assertSame('completed',$jobs->step($id)['status']);try{$jobs->create('retention_cleanup',null,['estimated_records'=>0]);self::fail('New job bypassed maintenance policy.');}catch(\Modright\HttpException$e){self::assertSame(503,$e->status);} }

    public function testLegacyMaintenanceJobPolicyIsPreservedOnUpgrade():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$settings=new SystemSettings($this->database());$settings->set('system.maintenance',['pause_jobs'=>false]);$state=$settings->group('maintenance');self::assertFalse($state['pause_new_jobs']);self::assertFalse($state['pause_running_jobs']);}

    public function testMaintenanceCanPauseMailWithoutCallingTransport():void
    { $called=false;$mail=new MailService(['paused'=>true,'from_address'=>'noreply@example.test'],function()use(&$called){$called=true;});try{$mail->send('user@example.test','Test','Body');self::fail('Paused mail must not be delivered.');}catch(\RuntimeException$e){self::assertSame('Mail delivery is paused during maintenance.',$e->getMessage());}self::assertFalse($called); }

    public function testDisabledModrinthDiagnosticDoesNotLeakConfiguration():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$settings=new SystemSettings($db);$features=$settings->group('features');$features['modrinth']=false;$settings->setGroup('features',$features);$report=(new \Modright\ModrinthClient($settings))->diagnose();self::assertSame('disabled',$report['status']);self::assertSame('disabled',$report['stage']);self::assertStringNotContainsString('password',strtolower(json_encode($report)));}

    public function testDisabledModrinthBlocksExternalJobsButKeepsLocalOperations():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$settings=new SystemSettings($db);$settings->setFeature('modrinth',false);$jobs=new \Modright\JobService($db,new \Modright\PackRepository($db),new \Modright\ModrinthClient($settings),new \Modright\ArchiveService(),null,$settings);foreach(['import','update_check','apply_updates','sync_files','catalog_sync','migration_scan']as$type){try{$jobs->create($type,null);self::fail($type.' bypassed disabled Modrinth connectivity.');}catch(\Modright\HttpException$e){self::assertSame(503,$e->status);}}$local=$jobs->create('retention_cleanup',null,['estimated_records'=>0]);self::assertSame('retention_cleanup',$jobs->find($local)['type']);}

    public function testHealthReportsLastSuccessfulAndFailedOperationsWithoutNetworkRequests():void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();
        $db=$this->database();$settings=new SystemSettings($db);
        $db->prepare('INSERT INTO cron_runs (id,status,processed,error,started_at,finished_at) VALUES (?,?,?,?,?,?)')->execute([Database::id(),'success',1,'','2026-01-01T00:00:00Z','2026-01-01T00:01:00Z']);
        $db->prepare('INSERT INTO cron_runs (id,status,processed,error,started_at,finished_at) VALUES (?,?,?,?,?,?)')->execute([Database::id(),'failed',0,'bounded failure','2026-01-02T00:00:00Z','2026-01-02T00:01:00Z']);
        $settings->set('system.update_last_success','2026-01-03T00:00:00Z');$settings->set('system.update_last_failure','2026-01-04T00:00:00Z');
        foreach([['backup.exported','2026-01-05T00:00:00Z'],['backup.failed','2026-01-06T00:00:00Z'],['mail.test_sent','2026-01-07T00:00:00Z'],['mail.test_failed','2026-01-08T00:00:00Z']]as[$action,$at])$db->prepare('INSERT INTO audit_log (id,action,context,created_at) VALUES (?,?,?,?)')->execute([Database::id(),$action,'{}',$at]);
        $checks=[];foreach((new \Modright\HealthService($db,$settings))->report(['HTTPS'=>'on'])['checks']as$check)$checks[$check['id']]=$check;
        foreach(['cron','update_check','backup','mail_test']as$id){self::assertStringContainsString('Last success:',$checks[$id]['message']);self::assertStringContainsString('Last failure:',$checks[$id]['message']);self::assertSame('degraded',$checks[$id]['status']);}
        self::assertStringContainsString('longer than one hour',$checks['background_jobs']['message']);
        self::assertStringContainsString('failures in the last 24 hours',$checks['background_jobs']['message']);
    }

    public function testAnnouncementsRespectAudienceAndDismissal(): void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$users=new UserService($db);$id=$users->create('announce','Announce','long-enough-password','user','de_DE','announce@example.test');$announcement=Database::id();$now=Database::now();$db->prepare('INSERT INTO announcements (id,severity,audience,title_en,message_en,title_de,message_de,target_url,dismissible,starts_at,ends_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$announcement,'info','authenticated','Hello','Message','Hallo','Nachricht','',1,null,null,$now,$now]);$service=new AnnouncementService($db);self::assertSame('Hallo',$service->activeFor($users->find($id),'de_DE')[0]['title']);$service->dismiss($announcement,$id);self::assertSame([],$service->activeFor($users->find($id),'de_DE'));
    }

    public function testAnnouncementManagerValidatesAndPersistsPlainContent(): void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$users=new UserService($db);$admin=$users->create('announceadmin','Admin','long-enough-password','admin','en_US','announceadmin@example.test');$service=new AnnouncementService($db);$id=$service->save(['severity'=>'warning','audience'=>'everyone','title_en'=>'Notice','message_en'=>'Plain <b>text</b>','title_de'=>'Hinweis','message_de'=>'Text','target_url'=>'https://example.test/help','dismissible'=>true,'created_by'=>$admin]);self::assertCount(1,$service->all());self::assertSame('Plain <b>text</b>',$service->all()[0]['message_en']);$service->delete($id);self::assertSame([],$service->all());
    }

    public function testOnlyEveryoneAnnouncementsAreVisibleBeforeLogin():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$service=new AnnouncementService($this->database());$service->save(['severity'=>'info','audience'=>'everyone','title_en'=>'Public','message_en'=>'Visible']);$service->save(['severity'=>'info','audience'=>'authenticated','title_en'=>'Private','message_en'=>'Hidden']);$active=$service->activeFor(null);self::assertCount(1,$active);self::assertSame('Public',$active[0]['title']);}

    public function testAnnouncementActivationAndExpiryAreAuditedOnce():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$service=new AnnouncementService($db);$service->save(['severity'=>'info','audience'=>'everyone','title_en'=>'Active','message_en'=>'Visible','starts_at'=>gmdate('c',time()-60),'ends_at'=>gmdate('c',time()+60)]);$service->save(['severity'=>'info','audience'=>'everyone','title_en'=>'Expired','message_en'=>'Hidden','starts_at'=>gmdate('c',time()-120),'ends_at'=>gmdate('c',time()-60)]);$service->activeFor(null);$service->activeFor(null);$actions=array_column((new AuditService($db))->recent(20),'action');self::assertSame(1,count(array_filter($actions,static fn(string$action):bool=>$action==='announcement.activated')));self::assertSame(1,count(array_filter($actions,static fn(string$action):bool=>$action==='announcement.expired')));}

    public function testAnnouncementSchedulesNormalizeTimeZonesAndEditingResetsLifecycle():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$service=new AnnouncementService($db);$id=$service->save(['severity'=>'info','audience'=>'everyone','title_en'=>'Offset','message_en'=>'Scheduled','starts_at'=>'2026-07-19T12:00:00+02:00','ends_at'=>'2026-07-19T13:00:00+02:00']);$stored=$service->find($id);self::assertSame('2026-07-19T10:00:00+00:00',$stored['starts_at']);self::assertSame('2026-07-19T11:00:00+00:00',$stored['ends_at']);$db->prepare('UPDATE announcements SET activated_at=?,expired_at=? WHERE id=?')->execute([Database::now(),Database::now(),$id]);$service->save(['severity'=>'warning','audience'=>'everyone','title_en'=>'Rescheduled','message_en'=>'Later','starts_at'=>'2027-01-01T00:00:00Z','ends_at'=>'2027-01-02T00:00:00Z'],$id);$edited=$service->find($id);self::assertNull($edited['activated_at']);self::assertNull($edited['expired_at']);}

    public function testCriticalAnnouncementCannotBeDismissedAndGermanFallsBackToEnglish():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$users=new UserService($db);$user=$users->create('critical-user','Critical User','long-enough-password','user','de_DE','critical@example.test');$service=new AnnouncementService($db);$id=$service->save(['severity'=>'critical','audience'=>'user:'.$user,'title_en'=>'Security notice','message_en'=>'Review now','dismissible'=>true]);self::assertSame('Security notice',$service->activeFor($users->find($user),'de_DE')[0]['title']);$this->expectException(\InvalidArgumentException::class);$service->dismiss($id,$user);}

    public function testAnnouncementLinksRejectProtocolRelativeCredentialsAndControlCharacters():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$service=new AnnouncementService($this->database());foreach(['//evil.test/path','https://user:pass@example.test/',"/help\nX-Test: bad"]as$url){try{$service->save(['severity'=>'info','audience'=>'everyone','title_en'=>'Title','message_en'=>'Message','target_url'=>$url]);self::fail('Unsafe announcement URL was accepted.');}catch(\InvalidArgumentException){self::assertTrue(true);}}}

    public function testOverlappingAnnouncementsAndEveryAudienceRemainIsolated():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$users=new UserService($db);$adminId=$users->create('audience-admin','Admin','long-enough-password','admin','en_US','audience-admin@example.test');$ownerId=$users->create('audience-owner','Owner','long-enough-password','user','en_US','audience-owner@example.test');$selectedId=$users->create('audience-selected','Selected','long-enough-password','user','en_US','audience-selected@example.test');$otherId=$users->create('audience-other','Other','long-enough-password','user','en_US','audience-other@example.test');$now=Database::now();$db->exec("INSERT INTO packs (id,name,slug,version_id,summary,game_version,loader,loader_version,index_json,created_at,updated_at) VALUES ('audience-pack','Pack','audience-pack','1','','1.20.1','fabric','1','{}','{$now}','{$now}')");$db->prepare('INSERT INTO pack_owners (pack_id,user_id,created_at) VALUES (?,?,?)')->execute(['audience-pack',$ownerId,$now]);$service=new AnnouncementService($db);foreach([['everyone','Everyone'],['authenticated','Authenticated'],['administrators','Administrators'],['pack_owners','Owners'],['user:'.$selectedId,'Selected']]as[$audience,$title])$service->save(['severity'=>'info','audience'=>$audience,'title_en'=>$title,'message_en'=>'Visible','starts_at'=>gmdate('c',time()-60),'ends_at'=>gmdate('c',time()+60)]);$titles=fn(string$id):array=>array_column($service->activeFor($users->find($id)),'title');self::assertEqualsCanonicalizing(['Everyone','Authenticated','Administrators'],$titles($adminId));self::assertEqualsCanonicalizing(['Everyone','Authenticated','Owners'],$titles($ownerId));self::assertEqualsCanonicalizing(['Everyone','Authenticated','Selected'],$titles($selectedId));self::assertEqualsCanonicalizing(['Everyone','Authenticated'],$titles($otherId));$service->save(['severity'=>'info','audience'=>'everyone','title_en'=>'Future','message_en'=>'Hidden','starts_at'=>gmdate('c',time()+3600)]);self::assertNotContains('Future',$titles($otherId));}
}
