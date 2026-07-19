<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\Database;
use Modright\JobService;
use Modright\PackRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class DryRunJobTest extends TestCase
{
    public function testBuildDryRunDoesNotChangePackOrCreatePackage(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is unavailable.');
        }

        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA foreign_keys = ON');
        Database::migrate($db);
        $packs = new PackRepository($db);
        $packId = $packs->create([
            'game' => 'minecraft', 'formatVersion' => 1, 'name' => 'Dry Test',
            'versionId' => '1.0.0', 'summary' => '', 'files' => [],
            'dependencies' => ['minecraft' => '1.21', 'fabric-loader' => '0.16.0'],
        ]);

        $jobs = new JobService($db, $packs);
        $jobId = $jobs->create('build', $packId, ['version' => '2.0.0', 'summary' => 'preview', 'dry_run' => true]);
        $job = $jobs->step($jobId);

        self::assertSame('completed', $job['status']);
        self::assertSame('1.0.0', $packs->find($packId)['version_id']);
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM packages')->fetchColumn());
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM backups')->fetchColumn());
        $packs->delete($packId);
    }
}
