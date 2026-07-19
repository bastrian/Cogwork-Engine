<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\Database;
use PDO;
use PHPUnit\Framework\TestCase;

final class DatabaseMigrationTest extends TestCase
{
    public function testLegacySqliteOperationalDataSurvivesIdempotentUpgrade():void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();
        $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');
        $db->exec("CREATE TABLE admins (id VARCHAR(36) PRIMARY KEY,username VARCHAR(100) NOT NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,created_at VARCHAR(30) NOT NULL)");
        $db->exec("CREATE TABLE packs (id VARCHAR(36) PRIMARY KEY,name VARCHAR(200) NOT NULL,slug VARCHAR(200) NOT NULL UNIQUE,version_id VARCHAR(100) NOT NULL,summary TEXT NOT NULL,game_version VARCHAR(100) NOT NULL,loader VARCHAR(30) NOT NULL,loader_version VARCHAR(100) NOT NULL,index_json TEXT NOT NULL,created_at VARCHAR(30) NOT NULL,updated_at VARCHAR(30) NOT NULL)");
        $db->exec("CREATE TABLE jobs (id VARCHAR(36) PRIMARY KEY,pack_id VARCHAR(36) NULL,type VARCHAR(40) NOT NULL,status VARCHAR(20) NOT NULL,payload TEXT NOT NULL,result TEXT NOT NULL,progress_current INTEGER NOT NULL DEFAULT 0,progress_total INTEGER NOT NULL DEFAULT 0,error TEXT NOT NULL,lock_token VARCHAR(64) NULL,created_at VARCHAR(30) NOT NULL,updated_at VARCHAR(30) NOT NULL)");
        $db->exec("CREATE TABLE backups (id VARCHAR(36) PRIMARY KEY,pack_id VARCHAR(36) NOT NULL,version_id VARCHAR(100) NOT NULL,path TEXT NOT NULL,created_at VARCHAR(30) NOT NULL)");
        $db->exec("CREATE TABLE packages (id VARCHAR(36) PRIMARY KEY,pack_id VARCHAR(36) NOT NULL,version_id VARCHAR(100) NOT NULL,path TEXT NOT NULL,size INTEGER NOT NULL,sha256 VARCHAR(64) NOT NULL,created_at VARCHAR(30) NOT NULL)");
        $db->exec("CREATE TABLE audit_log (id VARCHAR(36) PRIMARY KEY,action VARCHAR(100) NOT NULL,context TEXT NOT NULL,created_at VARCHAR(30) NOT NULL)");
        $now='2025-01-01T00:00:00+00:00';$hash=password_hash('legacy-password',PASSWORD_DEFAULT);
        $db->prepare('INSERT INTO admins VALUES (?,?,?,?)')->execute(['admin-id','legacy',$hash,$now]);
        $db->prepare('INSERT INTO packs VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute(['pack-id','Legacy Pack','legacy-pack','1.0','','1.20.1','fabric','0.15','{"files":[]}',$now,$now]);
        $db->prepare('INSERT INTO jobs VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute(['job-id','pack-id','build','completed','{}','{}',1,1,'',null,$now,$now]);
        $db->prepare('INSERT INTO backups VALUES (?,?,?,?,?)')->execute(['backup-id','pack-id','1.0','storage/backups/legacy.json',$now]);
        $db->prepare('INSERT INTO packages VALUES (?,?,?,?,?,?,?)')->execute(['package-id','pack-id','1.0','storage/packages/legacy.zip',123,str_repeat('a',64),$now]);
        $db->prepare('INSERT INTO audit_log VALUES (?,?,?,?)')->execute(['audit-id','legacy.event','{}',$now]);
        Database::migrate($db);Database::migrate($db);
        self::assertSame($hash,$db->query("SELECT password_hash FROM users WHERE id='admin-id'")->fetchColumn());
        self::assertSame('admin-id',$db->query("SELECT user_id FROM pack_owners WHERE pack_id='pack-id'")->fetchColumn());
        self::assertSame('completed',$db->query("SELECT status FROM jobs WHERE id='job-id'")->fetchColumn());
        self::assertSame('backup-id',$db->query("SELECT id FROM backups WHERE id='backup-id'")->fetchColumn());
        self::assertSame('package-id',$db->query("SELECT id FROM packages WHERE id='package-id'")->fetchColumn());
        self::assertSame('legacy.event',$db->query("SELECT action FROM audit_log WHERE id='audit-id'")->fetchColumn());
        self::assertSame(1,(int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='validation_reports'")->fetchColumn());
        self::assertSame(1,(int)$db->query("SELECT COUNT(*) FROM schema_migrations WHERE migration_id='".Database::LATEST_MIGRATION."'")->fetchColumn());
    }
}
