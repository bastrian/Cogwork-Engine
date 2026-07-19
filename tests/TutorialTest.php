<?php
declare(strict_types=1);
namespace Modright\Tests;
use Modright\Database;
use Modright\UserService;
use PDO;
use PHPUnit\Framework\TestCase;

final class TutorialTest extends TestCase
{
    public function testTutorialCanProgressSkipCompleteAndRestartPerUser(): void
    { if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);Database::migrate($db);$users=new UserService($db);$id=$users->create('tutorialuser','Tutorial User','long-enough-password','user','de_DE');self::assertSame('not_started',$users->find($id)['tutorial_status']);$users->tutorial($id,'in_progress',2);self::assertSame(2,(int)$users->find($id)['tutorial_step']);$users->tutorial($id,'skipped',2);self::assertSame('skipped',$users->find($id)['tutorial_status']);$users->tutorial($id,'completed',5);self::assertSame('completed',$users->find($id)['tutorial_status']);$users->tutorial($id,'in_progress',0);$restarted=$users->find($id);self::assertSame('in_progress',$restarted['tutorial_status']);self::assertSame(0,(int)$restarted['tutorial_step']);self::assertSame('de_DE',$restarted['locale']); }
}
