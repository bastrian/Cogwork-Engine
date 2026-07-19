<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\Database;
use PHPUnit\Framework\TestCase;

final class DatabasePathTest extends TestCase
{
    public function testRelativeSqlitePathUsesApplicationRoot(): void
    {
        self::assertSame(MODRIGHT_ROOT . '/storage/custom.sqlite', Database::sqlitePath('storage/custom.sqlite'));
    }

    public function testAbsoluteSqlitePathIsPreserved(): void
    {
        self::assertSame('/var/lib/modright/database.sqlite', Database::sqlitePath('/var/lib/modright/database.sqlite'));
    }

    public function testStreamWrapperIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Database::sqlitePath('file:///tmp/database.sqlite');
    }

    public function testRelativeTraversalIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Database::sqlitePath('../database.sqlite');
    }
}
