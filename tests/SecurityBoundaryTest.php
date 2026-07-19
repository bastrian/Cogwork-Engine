<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\ArchiveService;
use Modright\ModrinthClient;
use Modright\PackRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityBoundaryTest extends TestCase
{
    public function testApprovedModrinthUrlsAreAccepted(): void
    {
        ModrinthClient::assertUrl('https://cdn.modrinth.com/data/abc/versions/def/mod.jar');
        ModrinthClient::assertUrl('https://api.modrinth.com/v2/version/abc');
        ModrinthClient::assertUrl('https://github.com/example/project/releases/download/1/mod.jar');
        ModrinthClient::assertUrl('https://raw.githubusercontent.com/example/project/main/mod.jar');
        ModrinthClient::assertUrl('https://gitlab.com/example/project/-/raw/main/mod.jar');
        self::assertTrue(true);
    }

    public function testRelativeRedirectIsResolved(): void
    {
        self::assertSame('https://github.com/releases/file.jar', ModrinthClient::redirectUrl('https://github.com/project/download', '/releases/file.jar'));
    }

    #[DataProvider('unsafeUrls')]
    public function testUnsafeUrlsAreRejected(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModrinthClient::assertUrl($url);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeUrls(): iterable
    {
        yield 'http' => ['http://cdn.modrinth.com/file.jar'];
        yield 'lookalike' => ['https://cdn.modrinth.com.example.test/file.jar'];
        yield 'credentials' => ['https://user@cdn.modrinth.com/file.jar'];
        yield 'private host' => ['https://127.0.0.1/file.jar'];
    }

    #[DataProvider('unsafePaths')]
    public function testUnsafePackPathsAreRejected(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PackRepository::assertRelativePath($path);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafePaths(): iterable
    {
        yield ['../secret']; yield ['/etc/passwd']; yield ['mods/../secret']; yield ['C:\\secret']; yield ['mods//file.jar'];
    }

    #[DataProvider('unsafeArchivePaths')]
    public function testUnsafeArchiveEntriesAreRejected(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ArchiveService::assertEntry($path);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeArchivePaths(): iterable
    {
        yield ['../index.php']; yield ['/absolute']; yield ['overrides/../../index.php']; yield ["bad\0name"];
    }
}
