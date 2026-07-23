<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\BackupService;
use Modright\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class BackupServiceTest extends TestCase
{
    public function testExportPublishesCompleteArchiveWithoutPartialFile(): void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped();
        $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);Database::migrate($db);
        $path=sys_get_temp_dir().'/cogwork-backup-'.bin2hex(random_bytes(6)).'.zip';
        try{(new BackupService($db))->export($path);self::assertFileExists($path);self::assertFileDoesNotExist($path.'.part');$zip=new ZipArchive();self::assertTrue($zip->open($path)===true);self::assertNotFalse($zip->locateName('backup.json'));$zip->close();}finally{@unlink($path);@unlink($path.'.part');}
    }
}
