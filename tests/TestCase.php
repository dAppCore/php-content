<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            \Core\Tenant\Boot::class,
            \Core\Mod\Content\Boot::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Stub external dependencies not available in test environment
        if (! class_exists(\Plug\Cdn\CdnManager::class)) {
            $app->bind(\Core\Mod\Content\Services\CdnPurgeService::class, fn () => new class {
                public function isEnabled(): bool { return false; }
                public function purgeContent($content) { return null; }
                public function purgeUrls(array $urls) { return null; }
                public function purgeWorkspace(string $uuid) { return null; }
            });
        }
    }
}
