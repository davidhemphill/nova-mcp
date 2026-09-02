<?php

namespace Hemp\NovaMcp\Tests;

use Hemp\NovaMcp\Tests\Fixtures\Models\User;
use Hemp\NovaMcp\ToolServiceProvider;
use Inertia\ServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Nova\NovaCoreServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ServiceProvider::class,
            NovaCoreServiceProvider::class,
            McpServiceProvider::class,
            ToolServiceProvider::class,
            Fixtures\NovaServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.providers.users.model', User::class);

        // Nova's layout resolves its published assets through mix(), and the
        // testbench skeleton never runs nova:publish. Rendering only needs the
        // manifest, not the assets themselves.
        $manifest = $app->publicPath('vendor/nova/mix-manifest.json');

        if (! is_file($manifest)) {
            @mkdir(dirname($manifest), 0755, true);
            copy(dirname(__DIR__).'/vendor/laravel/nova/public/mix-manifest.json', $manifest);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
        $this->loadMigrationsFrom(dirname(__DIR__).'/vendor/laravel/nova/database/migrations');
    }
}
