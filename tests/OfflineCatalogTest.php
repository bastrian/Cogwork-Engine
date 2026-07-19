<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\Database;
use Modright\OfflineCatalog;
use PDO;
use PHPUnit\Framework\TestCase;

final class OfflineCatalogTest extends TestCase
{
    public function testExtractsUniqueProjectIdsAndStoresMetadata(): void
    {
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))self::markTestSkipped('pdo_sqlite unavailable.');$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);Database::migrate($db);$catalog=new OfflineCatalog($db);$index=['files'=>[['downloads'=>['https://cdn.modrinth.com/data/abc/versions/one/a.jar']],['downloads'=>['https://cdn.modrinth.com/data/abc/versions/two/b.jar']]]];self::assertSame(['abc'],OfflineCatalog::projectIds($index));$catalog->save('abc',['title'=>'Example'],[['id'=>'v1']],'1.21.1','fabric');$stats=$catalog->stats(['abc','missing']);self::assertSame(2,$stats['total']);self::assertSame(1,$stats['cached']);self::assertSame(0,$stats['failed']);
    }
}
