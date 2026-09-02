<?php

declare(strict_types=1);

namespace NovaAi\McpTools\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use NovaAi\McpTools\Nova\TokenAuthenticator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate the MCP endpoint with a Nova-minted token.
 *
 * Runs instead of Nova's session middleware: an MCP client has no cookie and no
 * CSRF token, so the bearer token is the only credential it can present.
 */
class AuthenticateMcpToken
{
    public function __construct(protected TokenAuthenticator $authenticator)
    {
        //
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->authenticator->authenticate(
            $this->authenticator->fromRequest($request),
        );

        if ($user === null) {
            return response()->json([
                'error' => 'invalid_token',
                'error_description' => 'A valid Nova MCP token is required. Mint one from the MCP Tools screen in Nova.',
            ], 401, ['WWW-Authenticate' => 'Bearer realm="nova-mcp", error="invalid_token"']);
        }

        $request->setUserResolver(fn (): mixed => $user);

        return $next($request);
    }
}
