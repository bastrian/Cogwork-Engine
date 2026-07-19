<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\Database;
use Modright\AuditService;
use Modright\FeatureControl;
use Modright\SystemSettings;
use Modright\UserService;
use PDO;
use PHPUnit\Framework\TestCase;

final class SystemSettingsTest extends TestCase
{
    private function database(): PDO
    {
        $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');Database::migrate($db);return$db;
    }

    public function testDefaultsCanBeOverriddenAndExportedWithoutUnknownKeys(): void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();
        $settings=new SystemSettings($this->database());
        self::assertTrue($settings->feature('modrinth'));
        $settings->setFeature('modrinth',false);
        self::assertFalse($settings->feature('modrinth'));
        $settings->setGroup('security',['canonical_url'=>'https://packs.example.test']);
        self::assertSame('https://packs.example.test',$settings->group('security')['canonical_url']);
        self::assertSame(1,$settings->export()['schema']);
    }

    public function testUnknownFeaturesAndGroupsAreRejected(): void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();
        $settings=new SystemSettings($this->database());
        $this->expectException(\InvalidArgumentException::class);
        $settings->setFeature('not-real',true);
    }

    public function testPasskeysRequireHttpsAndTwoFactorInProposedFeatureState():void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();$settings=new SystemSettings($this->database());$settings->setFeature('two_factor',false);$control=new FeatureControl($settings);$errors=$control->validate('passkeys',true,['passkeys']);self::assertContains('Configure a canonical HTTPS URL first.',$errors);self::assertContains('Enable two-factor authentication first.',$errors);$settings->setGroup('security',['canonical_url'=>'https://packs.example.test']);self::assertSame([],$control->validate('passkeys',true,['two_factor','passkeys']));
    }

    public function testFeatureChangesAreAuditedWithPreviousStateAndReason():void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();
        $db=$this->database();$adminId=(new UserService($db))->create('feature-admin','Feature Admin','long-enough-password','admin','en_US','feature-admin@example.test');$settings=new SystemSettings($db);$control=new FeatureControl($settings,new AuditService($db));
        $control->set('modrinth',false,$adminId,'Planned offline maintenance');
        $row=$db->query("SELECT action,context FROM audit_log WHERE action='feature.changed' ORDER BY created_at DESC LIMIT 1")->fetch();
        self::assertSame('feature.changed',$row['action']);$context=json_decode((string)$row['context'],true,512,JSON_THROW_ON_ERROR);
        self::assertSame('modrinth',$context['feature']);self::assertTrue($context['before']);self::assertFalse($context['after']);
        self::assertSame('Planned offline maintenance',$context['reason']);self::assertSame($adminId,$context['actor_user_id']);
    }

    public function testMailAndHttpsDependenciesAreValidatedBeforeSaving():void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();
        $settings=new SystemSettings($this->database());$control=new FeatureControl($settings);
        self::assertContains('Configure a canonical HTTPS URL first.',$control->validate('password_recovery',true));
        self::assertContains('Configure a sender email address first.',$control->validate('password_recovery',true));
        $settings->setGroup('security',['canonical_url'=>'https://packs.example.test']);$settings->setGroup('mail',['from_address'=>'system@example.test']);
        self::assertSame([],$control->validate('password_recovery',true));
    }
}
