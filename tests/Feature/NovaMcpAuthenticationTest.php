<?php

namespace Hemp\NovaMcp\Tests\Feature;

use Hemp\NovaMcp\Exceptions\NotAuthenticatedException;
use Hemp\NovaMcp\Http\Middleware\AuthenticateMcpToken;
use Hemp\NovaMcp\Mcp\NovaServer;
use Hemp\NovaMcp\Models\McpToken;
use Hemp\NovaMcp\Nova\NovaContext;
use Hemp\NovaMcp\Tests\Fixtures\Models\User;
use Hemp\NovaMcp\Tests\TestCase;
use Hemp\NovaMcp\ToolServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;

/**
 * The MCP endpoint must never act as a user who did not present a live token.
 */
class NovaMcpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_rejects_an_unauthenticated_request(): void
    {
        $this->postJson($this->endpoint(), $this->initialize())
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate')
            ->assertJsonPath('error', 'invalid_token');
    }

    #[Test]
    public function it_rejects_an_unknown_token(): void
    {
        $this->withToken('novamcp_'.str_repeat('a', 48))
            ->postJson($this->endpoint(), $this->initialize())
            ->assertUnauthorized();
    }

    #[Test]
    public function it_rejects_a_token_without_the_expected_prefix(): void
    {
        $this->withToken('just-some-string')
            ->postJson($this->endpoint(), $this->initialize())
            ->assertUnauthorized();
    }

    #[Test]
    public function it_accepts_a_minted_token(): void
    {
        $this->withToken($this->tokenFor($this->novaUser()))
            ->postJson($this->endpoint(), $this->initialize())
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'Laravel Nova');
    }

    #[Test]
    public function it_rejects_a_revoked_token(): void
    {
        $user = $this->novaUser();
        $token = McpToken::mint($user, 'Doomed');
        $plainText = $token->plainTextToken;

        $token->delete();

        $this->withToken($plainText)
            ->postJson($this->endpoint(), $this->initialize())
            ->assertUnauthorized();
    }

    #[Test]
    public function it_rejects_an_expired_token(): void
    {
        $token = McpToken::mint($this->novaUser(), 'Stale', now()->subMinute());

        $this->withToken($token->plainTextToken)
            ->postJson($this->endpoint(), $this->initialize())
            ->assertUnauthorized();
    }

    #[Test]
    public function it_rejects_a_token_whose_owner_cannot_access_nova(): void
    {
        $stranger = User::factory()->create(['email' => 'stranger@example.com']);

        $this->withToken($this->tokenFor($stranger))
            ->postJson($this->endpoint(), $this->initialize())
            ->assertForbidden();
    }

    #[Test]
    public function it_acts_as_the_token_owner(): void
    {
        $user = $this->novaUser(['name' => 'Ada Lovelace']);

        $this->withToken($this->tokenFor($user))
            ->postJson($this->endpoint(), [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/call',
                'params' => ['name' => 'nova_overview', 'arguments' => []],
            ])
            ->assertOk()
            ->assertSee('Ada Lovelace', escape: false)
            ->assertSee('hemp@hey.com', escape: false);
    }

    #[Test]
    public function it_records_when_a_token_was_last_used(): void
    {
        $token = McpToken::mint($this->novaUser(), 'Tracked');

        $this->assertNull($token->last_used_at);

        $this->withToken($token->plainTextToken)
            ->postJson($this->endpoint(), $this->initialize())
            ->assertOk();

        $this->assertNotNull($token->fresh()->last_used_at);
    }

    #[Test]
    public function the_stdio_transport_authenticates_its_configured_token(): void
    {
        $user = $this->novaUser();

        config()->set('nova-mcp.token', $this->tokenFor($user));

        $this->assertTrue($user->is(app(NovaContext::class)->user()));
    }

    #[Test]
    public function the_stdio_transport_refuses_a_revoked_token(): void
    {
        $token = McpToken::mint($this->novaUser(), 'Revoked');
        config()->set('nova-mcp.token', $token->plainTextToken);
        $token->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The MCP server is not authenticated.');

        app(NovaContext::class)->user();
    }

    #[Test]
    public function the_stdio_transport_refuses_when_no_token_is_configured(): void
    {
        config()->set('nova-mcp.token', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The MCP server is not authenticated.');

        app(NovaContext::class)->user();
    }

    #[Test]
    public function it_stores_only_a_digest_of_the_token(): void
    {
        $token = McpToken::mint($this->novaUser(), 'Hashed');

        $this->assertNotSame($token->plainTextToken, $token->token);
        $this->assertSame(hash('sha256', $token->plainTextToken), $token->token);
        $this->assertDatabaseMissing('nova_mcp_tokens', ['token' => $token->plainTextToken]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function novaUser(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['email' => 'hemp@hey.com']);
    }

    protected function tokenFor(User $user): string
    {
        return McpToken::mint($user, 'Testing')->plainTextToken;
    }

    #[Test]
    public function a_revoked_token_stops_working_without_a_restart(): void
    {
        $token = McpToken::mint($this->novaUser(), 'Long lived');
        config()->set('nova-mcp.token', $token->plainTextToken);

        $nova = app(NovaContext::class);
        $nova->boot();
        $this->assertNotNull($nova->user());

        $token->delete();

        // A stdio process handles many calls; the identity must not be cached
        // past the point the token stopped being valid.
        $this->expectException(NotAuthenticatedException::class);

        $nova->boot();
    }

    #[Test]
    public function the_stdio_token_cannot_authenticate_an_http_request(): void
    {
        config()->set('nova-mcp.token', McpToken::mint($this->novaUser(), 'Stdio only')->plainTextToken);

        // The configured token is the stdio credential. A web request carrying
        // no bearer token must not quietly fall back to it.
        $this->postJson($this->endpoint(), $this->initialize())
            ->assertUnauthorized();
    }

    #[Test]
    public function the_web_transport_is_not_registered_without_middleware(): void
    {
        config()->set('nova-mcp.web.middleware', []);

        Log::shouldReceive('error')->once()->withArgs(
            static fn (string $message): bool => str_contains($message, 'without authentication'),
        );

        $provider = new ToolServiceProvider($this->app);
        $servers = new ReflectionMethod($provider, 'servers');
        $servers->invoke($provider);

        $uri = (string) config('nova-mcp.web.route');
        $registered = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => $route->uri() === $uri && in_array('POST', $route->methods(), true));

        // Laravel keys routes by method and uri, so a rogue registration would
        // REPLACE the authenticated route rather than add a second one — and
        // Mcp::web() attaches its own plumbing middleware, so "non-empty" is
        // not the property either. Whatever route survives must still carry
        // the middleware that authenticates.
        $registered->each(function ($route): void {
            $this->assertContains(
                AuthenticateMcpToken::class,
                $route->gatherMiddleware(),
                'The MCP endpoint no longer authenticates its callers.',
            );
        });
    }

    #[Test]
    public function an_unauthenticated_refusal_answers_requests_but_never_notifications(): void
    {
        config()->set('nova-mcp.token', null);

        $transport = new class extends FakeTransporter
        {
            /** @var array<int, string> */
            public array $sent = [];

            public function send(string $message, ?string $sessionId = null): void
            {
                $this->sent[] = $message;
            }
        };

        $server = new NovaServer($transport);

        // JSON-RPC forbids responding to notifications, even to refuse them.
        $server->handle('{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"requestId":1}}');
        $this->assertSame([], $transport->sent);

        $server->handle('{"jsonrpc":"2.0","id":5,"method":"tools/list","params":{}}');
        $this->assertCount(1, $transport->sent);

        $response = json_decode($transport->sent[0], true);
        $this->assertSame(5, $response['id']);
        $this->assertSame(-32001, $response['error']['code']);
    }

    protected function endpoint(): string
    {
        return '/'.config('nova-mcp.web.route');
    }

    /**
     * @return array<string, mixed>
     */
    protected function initialize(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'tests', 'version' => '1'],
            ],
        ];
    }
}
