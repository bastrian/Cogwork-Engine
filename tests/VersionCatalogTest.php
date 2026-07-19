<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\VersionCatalog;
use PHPUnit\Framework\TestCase;

final class VersionCatalogTest extends TestCase
{
    public function testNeoForgeMinecraftVersionMapping(): void
    {
        self::assertSame('21.1', VersionCatalog::neoForgePrefix('1.21.1'));
        self::assertSame('20.2', VersionCatalog::neoForgePrefix('1.20.2'));
        self::assertSame('26.1', VersionCatalog::neoForgePrefix('26.1'));
    }

    public function testBuiltInFallbackContainsCommonSupportedReleases(): void
    {
        $reflection = new \ReflectionClass(VersionCatalog::class);
        $versions = $reflection->getConstant('MINECRAFT_FALLBACK');
        self::assertContains('1.21.1', $versions);
        self::assertContains('1.20.1', $versions);
        self::assertContains('1.16.5', $versions);
    }
}
