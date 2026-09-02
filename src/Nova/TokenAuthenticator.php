<?php

declare(strict_types=1);

namespace NovaAi\McpTools\Nova;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Laravel\Nova\Util;
use NovaAi\McpTools\Models\McpToken;

/**
 * Resolves the Nova user behind an MCP token.
 *
 * Both transports go through here, so HTTP and stdio accept exactly the same
 * credential and a revoked token stops working everywhere at once.
 */
class TokenAuthenticator
{
    /**
     * Authenticate a plaintext token, logging the user in on Nova's guard.
     */
    public function authenticate(?string $plainText): ?Authenticatable
    {
        if (blank($plainText)) {
            return null;
        }

        $token = McpToken::findByPlainText($plainText);
        $user = $token?->user;

        if (! $user instanceof Authenticatable) {
            return null;
        }

        $token->markAsUsed();

        auth()->guard(Util::userGuard())->setUser($user);

        return $user;
    }

    /**
     * Pull the token out of an HTTP request.
     */
    public function fromRequest(Request $request): ?string
    {
        return $request->bearerToken() ?: $request->header('X-Nova-Mcp-Token');
    }

    /**
     * The token configured for the stdio transport.
     */
    public function configured(): ?string
    {
        $token = config('nova-mcp.token');

        return is_string($token) && $token !== '' ? $token : null;
    }
}
