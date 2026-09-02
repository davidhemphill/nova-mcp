<?php

namespace Hemp\NovaMcp\Tests\Feature;

use Hemp\NovaMcp\Models\McpToken;
use Hemp\NovaMcp\Tests\Fixtures\Models\User;
use Hemp\NovaMcp\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class NovaMcpToolPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_mints_a_token_and_lists_it(): void
    {
        $user = User::factory()->create(['email' => 'hemp@hey.com']);

        $response = $this->actingAs($user)
            ->postJson('/nova-vendor/nova-mcp/tokens', ['name' => 'Claude Desktop'])
            ->assertCreated()
            ->assertJsonPath('name', 'Claude Desktop');

        $this->assertStringStartsWith(McpToken::PREFIX, $response->json('token'));

        $this->actingAs($user)
            ->getJson('/nova-vendor/nova-mcp/tokens')
            ->assertOk()
            ->assertJsonPath('tokens.0.name', 'Claude Desktop')
            ->assertJsonMissingPath('tokens.0.token');
    }

    #[Test]
    public function it_mints_a_token_that_expires(): void
    {
        $user = User::factory()->create(['email' => 'hemp@hey.com']);

        $this->actingAs($user)
            ->postJson('/nova-vendor/nova-mcp/tokens', [
                'name' => 'Short lived',
                'expires_in_days' => 7,
            ])
            ->assertCreated();

        $this->assertTrue(
            McpToken::firstWhere('name', 'Short lived')->expires_at->isBetween(now()->addDays(6), now()->addDays(8))
        );
    }

    #[Test]
    public function it_revokes_a_token(): void
    {
        $user = User::factory()->create(['email' => 'hemp@hey.com']);
        $token = McpToken::mint($user, 'Doomed');

        $this->actingAs($user)
            ->deleteJson('/nova-vendor/nova-mcp/tokens/'.$token->getKey())
            ->assertOk();

        $this->assertDatabaseMissing('nova_mcp_tokens', ['id' => $token->getKey()]);
    }

    #[Test]
    public function it_will_not_revoke_another_users_token(): void
    {
        $owner = User::factory()->create(['email' => 'hemp@hey.com']);
        $other = User::factory()->create(['email' => 'someone-else@example.com']);
        $token = McpToken::mint($other, 'Not yours');

        $this->actingAs($owner)
            ->deleteJson('/nova-vendor/nova-mcp/tokens/'.$token->getKey())
            ->assertOk();

        $this->assertDatabaseHas('nova_mcp_tokens', ['id' => $token->getKey()]);
    }

    #[Test]
    public function it_only_lists_the_acting_users_tokens(): void
    {
        $owner = User::factory()->create(['email' => 'hemp@hey.com']);
        $other = User::factory()->create(['email' => 'someone-else@example.com']);

        McpToken::mint($owner, 'Mine');
        McpToken::mint($other, 'Theirs');

        $this->actingAs($owner)
            ->getJson('/nova-vendor/nova-mcp/tokens')
            ->assertOk()
            ->assertJsonCount(1, 'tokens')
            ->assertJsonPath('tokens.0.name', 'Mine');
    }

    #[Test]
    public function it_refuses_token_management_from_users_who_cannot_access_nova(): void
    {
        $user = User::factory()->create(['email' => 'stranger@example.com']);

        $this->actingAs($user)
            ->postJson('/nova-vendor/nova-mcp/tokens', ['name' => 'Nope'])
            ->assertForbidden();
    }

    #[Test]
    public function it_serves_the_catalog_to_an_authorized_nova_user(): void
    {
        $user = User::factory()->create(['email' => 'hemp@hey.com']);

        $this->actingAs($user)
            ->getJson('/nova-vendor/nova-mcp/catalog')
            ->assertOk()
            ->assertJsonPath('capabilities.writes', true)
            ->assertJsonPath('transports.local.handle', 'nova')
            ->assertJsonFragment(['name' => 'nova_users_list']);
    }

    #[Test]
    public function it_refuses_users_who_cannot_access_nova(): void
    {
        $user = User::factory()->create(['email' => 'stranger@example.com']);

        $this->actingAs($user)
            ->getJson('/nova-vendor/nova-mcp/catalog')
            ->assertForbidden();
    }

    #[Test]
    public function it_renders_the_tool_screen_inside_nova(): void
    {
        $user = User::factory()->create(['email' => 'hemp@hey.com']);

        $this->actingAs($user)
            ->get('/nova/nova-mcp')
            ->assertOk()
            ->assertSee('NovaMcp', escape: false);
    }

    #[Test]
    public function it_refuses_guests(): void
    {
        $this->getJson('/nova-vendor/nova-mcp/catalog')->assertUnauthorized();
    }
}
