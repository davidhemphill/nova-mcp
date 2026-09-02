<?php

declare(strict_types=1);

namespace Hemp\NovaMcp\Nova;

use Closure;
use Hemp\NovaMcp\Exceptions\NotAuthenticatedException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Route;
use Laravel\Nova\Events\NovaServiceProviderRegistered;
use Laravel\Nova\Events\ServingNova;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use Laravel\Nova\Util;
use RuntimeException;

/**
 * Bridges the MCP server and Nova.
 *
 * Nova only registers its resources, dashboards and tools while it is "serving"
 * a request, and every piece of its authorization and field machinery expects a
 * NovaRequest bound into the container. This class provides both: it boots Nova
 * outside of an HTTP request, and it manufactures the request objects Nova's own
 * controllers would have received.
 */
class NovaContext
{
    /**
     * Guards against re-entering boot() while it is already running.
     */
    protected bool $booting = false;

    protected ?Authenticatable $actingUser = null;

    /**
     * Whether the acting user came from the configured stdio token.
     */
    protected bool $fromConfiguredToken = false;

    public function __construct(protected Application $app)
    {
        //
    }

    /**
     * Register Nova's resources, dashboards and tools for the call about to run.
     *
     * A stdio server outlives many calls, and Nova keeps its registration in
     * static state that is only filled while it is "serving". Booting once
     * would freeze the catalog at whatever `app/Nova` held when the process
     * started, so a resource added later stayed invisible until somebody
     * restarted their MCP client. Instead the registration is reset and
     * rebuilt on every call, which makes Nova rescan `app/Nova` and lets the
     * process answer from what is on disk right now.
     *
     * Only the four registration arrays are reset. Nova::flushState() would
     * also clear Fortify's configuration, and that is registered once in the
     * provider's register() rather than on serving, so it would never come
     * back in a long lived process.
     *
     * Over HTTP the "nova" middleware group has already done all of this for
     * the current request, so there is nothing to rebuild.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole() || $this->booting) {
            return;
        }

        $this->booting = true;

        try {
            // A stdio process handles many calls. Holding on to the user found
            // by the first one would keep a revoked or expired token working
            // until the client restarted, so an identity that came from the
            // token is dropped here and resolved again below.
            $this->forgetTokenIdentity();

            Nova::$resources = [];
            Nova::$resourcesByModel = [];
            Nova::$dashboards = [];
            Nova::$tools = [];

            $request = $this->request();

            $this->user();

            NovaServiceProviderRegistered::dispatch();
            ServingNova::dispatch($this->app, $request);

            Nova::bootResources();
            Nova::bootTools($request);
        } finally {
            $this->booting = false;
        }
    }

    /**
     * A fingerprint of what Nova currently has registered.
     *
     * Used to notice that the catalog changed between calls.
     */
    public function registrationFingerprint(): string
    {
        return md5(serialize([
            array_values(Nova::$resources),
            array_map(static fn (object $dashboard): string => $dashboard->uriKey(), Nova::$dashboards),
        ]));
    }

    /**
     * Set the user the server acts as, and authenticate them on Nova's guard.
     */
    public function actingAs(Authenticatable $user): void
    {
        $this->actingUser = $user;

        $this->app->make('auth')->guard(Util::userGuard())->setUser($user);
    }

    /**
     * Get the user the server is acting as.
     *
     * Over HTTP that is whoever Nova authenticated. The stdio transport has no
     * session, so it falls back to the user named in the package config.
     *
     * @throws RuntimeException
     */
    public function user(): Authenticatable
    {
        if ($this->actingUser !== null) {
            return $this->actingUser;
        }

        $user = Nova::user();

        if ($user !== null) {
            return $this->actingUser = $user;
        }

        $user = $this->tokenUser();

        if ($user === null) {
            throw new NotAuthenticatedException(
                'The MCP server is not authenticated. Mint a token from the Nova MCP screen in Nova and pass it as NOVA_MCP_TOKEN.'
            );
        }

        $this->actingAs($user);
        $this->fromConfiguredToken = true;

        return $user;
    }

    /**
     * Resolve the user from the token configured for the stdio transport.
     */
    protected function tokenUser(): ?Authenticatable
    {
        // The configured token is the stdio transport's credential. Over HTTP
        // the bearer token is the only thing that may authenticate a caller, so
        // a request that reached this far without one is not served.
        if (! $this->app->runningInConsole()) {
            return null;
        }

        $authenticator = $this->app->make(TokenAuthenticator::class);

        return $authenticator->authenticate($authenticator->configured());
    }

    /**
     * Drop an identity that came from the configured token, so it is re-checked.
     *
     * An identity established elsewhere — the bearer token middleware on the web
     * transport — is left alone; that credential was verified for this request
     * and there is nothing here to re-validate it against.
     */
    protected function forgetTokenIdentity(): void
    {
        if (! $this->fromConfiguredToken) {
            return;
        }

        $this->fromConfiguredToken = false;
        $this->actingUser = null;

        $guard = $this->app->make('auth')->guard(Util::userGuard());

        if (method_exists($guard, 'forgetUser')) {
            $guard->forgetUser();
        }
    }

    /**
     * Build a Nova request of the given type, as Nova's router would have.
     *
     * @template TRequest of \Laravel\Nova\Http\Requests\NovaRequest
     *
     * @param  class-string<TRequest>  $class
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $routeParameters
     * @return TRequest
     */
    public function request(
        string $class = NovaRequest::class,
        string $uri = '/nova-mcp',
        string $method = 'GET',
        array $parameters = [],
        array $routeParameters = [],
    ): NovaRequest {
        $request = $class::create($uri, $method, $parameters, [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        $request->setContainer($this->app);
        $request->setUserResolver(fn (?string $guard = null): ?Authenticatable => $this->user());

        // Nova reads the session while serializing rows, to work out whether the
        // current user is impersonating someone. Console requests have none.
        if (! $request->hasSession() && $this->app->bound('session')) {
            $request->setLaravelSession($this->app->make('session')->driver());
        }

        $route = new Route([$method], strtok($uri, '?') ?: '/', []);
        $route->bind($request);

        foreach ($routeParameters as $key => $value) {
            $route->setParameter($key, $value);
        }

        $request->setRouteResolver(fn (): Route => $route);

        return $request;
    }

    /**
     * Run the callback with the given request bound into the container.
     *
     * Nova's fields, filters and cards all reach for `app(NovaRequest::class)`
     * while serializing themselves, so the request has to be resolvable.
     *
     * @template TReturn
     *
     * @param  Closure(TRequest): TReturn  $callback
     * @return TReturn
     *
     * @phpstan-template TRequest of \Laravel\Nova\Http\Requests\NovaRequest
     */
    public function run(NovaRequest $request, Closure $callback): mixed
    {
        $abstracts = array_unique([NovaRequest::class, $request::class]);
        $previous = [];

        foreach ($abstracts as $abstract) {
            $previous[$abstract] = $this->app->resolved($abstract) && $this->app->isShared($abstract)
                ? $this->app->make($abstract)
                : null;

            $this->app->instance($abstract, $request);
        }

        try {
            return $callback($request);
        } finally {
            foreach ($previous as $abstract => $instance) {
                $instance instanceof NovaRequest
                    ? $this->app->instance($abstract, $instance)
                    : $this->app->forgetInstance($abstract);
            }
        }
    }

    /**
     * Build a request of the given type and immediately run the callback with it.
     *
     * @template TRequest of \Laravel\Nova\Http\Requests\NovaRequest
     * @template TReturn
     *
     * @param  class-string<TRequest>  $class
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $routeParameters
     * @param  Closure(TRequest): TReturn  $callback
     * @return TReturn
     */
    public function using(
        string $class,
        array $routeParameters,
        array $parameters,
        Closure $callback,
        string $uri = '/nova-mcp',
        string $method = 'GET',
    ): mixed {
        $this->boot();

        return $this->run(
            $this->request($class, $uri, $method, $parameters, $routeParameters),
            $callback,
        );
    }
}
