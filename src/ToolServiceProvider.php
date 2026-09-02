<?php

declare(strict_types=1);

namespace NovaAi\McpTools;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Nova\Http\Middleware\Authorize as NovaAuthorize;
use Laravel\Nova\Nova;
use NovaAi\McpTools\Http\Middleware\Authorize;
use NovaAi\McpTools\Mcp\NovaServer;
use NovaAi\McpTools\Nova\NovaContext;

class ToolServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->app->booted(function (): void {
            $this->routes();
            $this->servers();
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/nova-mcp.php' => config_path('nova-mcp.php'),
            ], 'nova-mcp-config');
        }
    }

    /**
     * Register the tool's routes.
     */
    protected function routes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Nova::router($this->middleware(), 'mcp-tools')
            ->group(__DIR__.'/../routes/inertia.php');

        Route::middleware($this->middleware())
            ->prefix('nova-vendor/mcp-tools')
            ->group(__DIR__.'/../routes/api.php');
    }

    /**
     * The middleware protecting the tool's own screens.
     *
     * Nova's tool stubs stop at authentication; the catalog describes the whole
     * admin surface, so the "viewNova" gate is enforced here as well.
     *
     * @return array<int, string>
     */
    protected function middleware(): array
    {
        return ['nova', 'nova.auth', NovaAuthorize::class, Authorize::class];
    }

    /**
     * Register the MCP transports that serve the Nova catalog.
     */
    protected function servers(): void
    {
        if (config('nova-mcp.local.enabled', true)) {
            Mcp::local((string) config('nova-mcp.local.handle', 'nova'), NovaServer::class);
        }

        if ($this->app->routesAreCached()) {
            return;
        }

        if (! config('nova-mcp.web.enabled', true)) {
            return;
        }

        $middleware = array_values(array_filter((array) config('nova-mcp.web.middleware', [])));

        // The endpoint has no authentication of its own; the middleware stack is
        // the whole of it. `mergeConfigFrom` merges only top level keys, so a
        // published config whose "web" array omits "middleware" would otherwise
        // silently publish the entire admin surface unauthenticated. Refuse to
        // register instead, and say so loudly.
        if ($middleware === []) {
            Log::error('The Nova MCP web transport was not registered: "nova-mcp.web.middleware" is empty, which would expose the endpoint without authentication. Restore the middleware stack or set "nova-mcp.web.enabled" to false.');

            return;
        }

        Route::middleware($middleware)
            ->group(function (): void {
                Mcp::web((string) config('nova-mcp.web.route', 'nova-vendor/mcp-tools/mcp'), NovaServer::class);
            });
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nova-mcp.php', 'nova-mcp');

        // Scoped so the acting user and Nova's boot state are shared for the
        // lifetime of one request, but never leak between them.
        $this->app->scoped(NovaContext::class);
    }
}
