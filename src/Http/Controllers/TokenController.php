<?php

declare(strict_types=1);

namespace NovaAi\McpTools\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Laravel\Nova\Http\Requests\NovaRequest;
use NovaAi\McpTools\Models\McpToken;

/**
 * Mints and revokes the tokens that authenticate the MCP server.
 *
 * A token belongs to the Nova user who minted it and acts as that user, so it
 * can never reach anything its owner could not reach in Nova itself. The
 * plaintext is returned exactly once, on creation.
 */
class TokenController extends Controller
{
    public function index(NovaRequest $request): JsonResponse
    {
        return response()->json([
            'tokens' => $this->tokens($request)->map(static fn (McpToken $token): array => [
                'id' => $token->getKey(),
                'name' => $token->name,
                'createdAt' => $token->created_at?->toAtomString(),
                'lastUsedAt' => $token->last_used_at?->toAtomString(),
                'expiresAt' => $token->expires_at?->toAtomString(),
            ])->values(),
        ]);
    }

    public function store(NovaRequest $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'expires_in_days' => ['nullable', 'integer', 'between:1,3650'],
        ]);

        $token = McpToken::mint(
            $request->user(),
            $validated['name'],
            isset($validated['expires_in_days'])
                ? now()->addDays($validated['expires_in_days'])
                : null,
        );

        return response()->json([
            'id' => $token->getKey(),
            'name' => $token->name,
            'token' => $token->plainTextToken,
            'expiresAt' => $token->expires_at?->toAtomString(),
        ], 201);
    }

    public function destroy(NovaRequest $request, string $token): Response
    {
        $this->tokens($request)
            ->firstWhere(static fn (McpToken $candidate): bool => (string) $candidate->getKey() === $token)
            ?->delete();

        return response()->noContent(200);
    }

    /**
     * The acting user's tokens.
     *
     * Scoped to the owner, so one Nova user can never revoke another's token.
     *
     * @return Collection<int, McpToken>
     */
    protected function tokens(NovaRequest $request)
    {
        $user = $request->user();

        return McpToken::query()
            ->where('user_type', $user->getMorphClass())
            ->where('user_id', $user->getKey())
            ->latest()
            ->get();
    }
}
