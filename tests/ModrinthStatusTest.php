<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\ModrinthStatus;
use PHPUnit\Framework\TestCase;

final class ModrinthStatusTest extends TestCase
{
    public function testOperationalPageIsClassifiedAsUp(): void
    {
        self::assertSame('up', ModrinthStatus::classify('<h1>All systems operational</h1>')['state']);
        self::assertSame('up', ModrinthStatus::classify('<h1>All services are online</h1>')['state']);
    }

    public function testPartialOutageIsClassifiedAsIssues(): void
    {
        self::assertSame('issues', ModrinthStatus::classify('<h1>Some services are down</h1>')['state']);
    }

    public function testUnrecognizedPageIsUnknown(): void
    {
        self::assertSame('unknown', ModrinthStatus::classify('<html>Unexpected response</html>')['state']);
    }
}
