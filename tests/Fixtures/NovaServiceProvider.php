<?php

namespace Hemp\NovaMcp\Tests\Fixtures;

use Hemp\NovaMcp\NovaMcp;
use Hemp\NovaMcp\Tests\Fixtures\Nova\ArticleResource;
use Hemp\NovaMcp\Tests\Fixtures\Nova\Main;
use Hemp\NovaMcp\Tests\Fixtures\Nova\TagResource;
use Hemp\NovaMcp\Tests\Fixtures\Nova\UserResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Nova\Nova;

/**
 * Stands in for a host application's NovaServiceProvider: registers the
 * fixture resources and this package's tool on Nova's serving event, and
 * defines the gate the way an application would.
 */
class NovaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Nova::serving(static function (): void {
            Nova::resources([
                UserResource::class,
                ArticleResource::class,
                TagResource::class,
            ]);

            Nova::dashboards([new Main]);
            Nova::tools([new NovaMcp]);
        });

        Gate::define('viewNova', static fn (object $user): bool => in_array($user->email, [
            'hemp@hey.com',
        ], true));

        // NovaApplicationServiceProvider::authorization() normally wires this;
        // without it Nova's Authorize middleware denies everyone outside the
        // local environment.
        Nova::auth(static fn ($request): bool => Gate::check('viewNova', [Nova::user($request)]));
    }
}
