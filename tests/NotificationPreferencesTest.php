<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\Database;
use Modright\NotificationService;
use Modright\SecurityEventService;
use Modright\SystemSettings;
use Modright\MailService;
use Modright\UserService;
use PDO;
use PHPUnit\Framework\TestCase;

final class NotificationPreferencesTest extends TestCase
{
    private function database():PDO
    { $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);Database::migrate($db);return$db; }

    public function testPreferencesSuppressOptionalButNeverSecurityNotices():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$user=(new UserService($db))->create('noticeuser','Notice User','long-enough-password','user','en_US','notice@example.test');$service=new NotificationService($db);$service->setPreferences($user,['jobs'],[]);self::assertSame('',$service->send($user,'updates','info','Update','Available'));self::assertNotSame('',$service->send($user,'security','critical','Security event','Review this'));self::assertTrue($service->preference($user,'security')['in_app']);self::assertTrue($service->preference($user,'security')['email']);self::assertSame(1,$service->page($user,1,25)['total']); }

    public function testPaginationIsBoundedAndOwnedByUser():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$users=new UserService($db);$first=$users->create('firstnotice','First','long-enough-password','user','en_US','first@example.test');$second=$users->create('secondnotice','Second','long-enough-password','user','en_US','second@example.test');$service=new NotificationService($db);for($i=0;$i<31;$i++)$service->send($first,'jobs','info','Job '.$i,'Done');$service->send($second,'jobs','info','Private','Other user');$page=$service->page($first,2,25);self::assertSame(31,$page['total']);self::assertSame(2,$page['pages']);self::assertCount(6,$page['items']);self::assertNotContains('Private',array_column($page['items'],'title')); }

    public function testSignificantSecurityMailIsRateLimitedAndDurable():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$user=(new UserService($db))->create('securemail','Secure Mail','long-enough-password','user','en_US','securemail@example.test');$sent=[];$mail=new MailService(['from_address'=>'noreply@example.test'],function($message)use(&$sent){$sent[]=$message;});$events=new SecurityEventService($db,$mail,new SystemSettings($db));$events->record($user,'session.created','warning');$events->record($user,'sessions.mass_revoked','warning');self::assertCount(1,$sent);self::assertSame(1,(new NotificationService($db))->unreadCount($user));self::assertStringNotContainsString('192.0.2.',json_encode($sent));self::assertStringNotContainsString('secret-value',json_encode($sent)); }

    public function testExactDuplicateNotificationsAreCoalescedForTenMinutes():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$user=(new UserService($db))->create('dedupe','Dedupe','long-enough-password','user','en_US','dedupe@example.test');$notifications=new NotificationService($db);$first=$notifications->send($user,'maintenance','warning','Maintenance scheduled','Maintenance starts soon.','/index.php?route=help',false);$second=$notifications->send($user,'maintenance','warning','Maintenance scheduled','Maintenance starts soon.','/index.php?route=help',false);self::assertSame($first,$second);self::assertSame(1,$notifications->unreadCount($user));}

    public function testBroadcastTargetsOnlyEnabledSelectedUsers():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$users=new UserService($db);$enabled=$users->create('broadcaston','On','long-enough-password','user','en_US','on@example.test');$disabled=$users->create('broadcastoff','Off','long-enough-password','user','en_US','off@example.test');$users->update($disabled,'Off','user',false,'en_US');$notifications=new NotificationService($db);self::assertSame(1,$notifications->broadcast('announcements','info','Notice','Message','',[$enabled,$disabled]));self::assertSame(1,$notifications->unreadCount($enabled));self::assertSame(0,$notifications->unreadCount($disabled));}

    public function testReadAcknowledgedAndArchivedStatesAreDurableAndSeparated():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$user=(new UserService($db))->create('states-user','States','long-enough-password','user','en_US','states@example.test');$service=new NotificationService($db);$id=$service->send($user,'jobs','info','Job complete','Finished');self::assertSame(1,$service->unreadCount($user));$service->markRead($id,$user);self::assertSame(0,$service->unreadCount($user));self::assertNotNull($service->page($user)['items'][0]['read_at']);$service->acknowledge($id,$user);self::assertNotNull($service->page($user)['items'][0]['acknowledged_at']);$service->archive($id,$user);self::assertSame(0,$service->page($user)['total']);$archived=$service->page($user,1,25,true);self::assertSame(1,$archived['total']);self::assertNotNull($archived['items'][0]['archived_at']);}

    public function testRemovedNotificationResourceLosesItsLinkButKeepsText():void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=$this->database();$user=(new UserService($db))->create('missing-target','Missing Target','long-enough-password','user','en_US','missing@example.test');$service=new NotificationService($db);$service->send($user,'permissions','info','Pack removed','The referenced pack is no longer available.','/index.php?route=packs%2Fview&id=missing-pack');$item=$service->page($user)['items'][0];self::assertSame('Pack removed',$item['title']);self::assertSame('',$item['target_url']);}
}
