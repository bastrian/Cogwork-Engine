<?php

declare(strict_types=1);

namespace Modright\Tests;

use Modright\Config;
use PHPUnit\Framework\TestCase;

final class ConfigEmergencyTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('COGWORK_MAINTENANCE_DISABLE');
    }

    public function testProtectedConfigurationCanDisableMaintenance(): void
    {
        putenv('COGWORK_MAINTENANCE_DISABLE');
        self::assertTrue(Config::maintenanceDisabledByHost(['emergency' => ['disable_maintenance' => true]]));
        self::assertFalse(Config::maintenanceDisabledByHost(['app_key' => 'test']));
    }

    public function testEnvironmentOverrideRemainsAvailable(): void
    {
        putenv('COGWORK_MAINTENANCE_DISABLE=1');
        self::assertTrue(Config::maintenanceDisabledByHost(['app_key' => 'test']));
    }
}
