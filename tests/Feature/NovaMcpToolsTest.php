<?php

namespace Hemp\NovaMcp\Tests\Feature;

use Hemp\NovaMcp\Mcp\NovaServer;
use Hemp\NovaMcp\Mcp\Tools\OverviewTool;
use Hemp\NovaMcp\Mcp\Tools\ResourceSchemaTool;
use Hemp\NovaMcp\Nova\Catalog;
use Hemp\NovaMcp\Nova\NovaContext;
use Hemp\NovaMcp\Nova\Schema;
use Hemp\NovaMcp\Tests\Fixtures\Models\User;
use Hemp\NovaMcp\Tests\Fixtures\Nova\Person;
use Hemp\NovaMcp\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Laravel\Nova\Nova;
use PHPUnit\Framework\Attributes\Test;

class NovaMcpToolsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);

        $this->actingAs($this->user);

        // These tests call catalog tools by name, so keep the server in its
        // direct-listing mode regardless of how many resources the app has.
        config()->set('nova-mcp.tool_search_threshold', 1000);
    }

    #[Test]
    public function it_sees_resources_registered_after_the_process_started(): void
    {
        $nova = app(NovaContext::class);

        $nova->boot();
        $before = $nova->registrationFingerprint();

        $this->assertNotContains(Person::class, Nova::$resources);

        // Stands in for someone adding a class to app/Nova while the stdio
        // server is running: Nova only picks it up while it is "serving".
        Nova::serving(static fn () => Nova::resources([Person::class]));

        $nova->boot();

        $this->assertContains(Person::class, Nova::$resources);
        $this->assertNotSame($before, $nova->registrationFingerprint());
    }

    #[Test]
    public function repeated_boots_do_not_duplicate_registrations(): void
    {
        $nova = app(NovaContext::class);

        $nova->boot();
        $nova->boot();
        $nova->boot();

        $this->assertSame(Nova::$resources, array_unique(Nova::$resources));

        $dashboards = array_map(static fn ($d): string => $d->uriKey(), Nova::$dashboards);
        $this->assertSame($dashboards, array_unique($dashboards));
    }

    #[Test]
    public function it_advertises_that_its_tool_list_can_change(): void
    {
        $context = (new NovaServer(new FakeTransporter))->createContext();

        $this->assertTrue($context->serverCapabilities['tools']['listChanged']);
    }

    #[Test]
    public function it_builds_a_catalog_tool_for_every_nova_operation(): void
    {
        $names = collect($this->catalog())->map(fn (Tool $tool): string => $tool->name())->all();

        $this->assertContains('nova_users_list', $names);
        $this->assertContains('nova_users_get', $names);
        $this->assertContains('nova_users_create', $names);
        $this->assertContains('nova_users_update', $names);
        $this->assertContains('nova_users_delete', $names);
        $this->assertContains('nova_dashboard_main', $names);
        $this->assertContains('nova_notifications', $names);
        $this->assertSame($names, array_unique($names), 'Catalog tool names must be unique.');
    }

    #[Test]
    public function it_omits_write_tools_when_writes_are_disabled(): void
    {
        config()->set('nova-mcp.writes', false);

        $names = collect($this->catalog())->map(fn (Tool $tool): string => $tool->name())->all();

        $this->assertContains('nova_users_list', $names);
        $this->assertNotContains('nova_users_create', $names);
        $this->assertNotContains('nova_users_delete', $names);
    }

    #[Test]
    public function the_overview_describes_the_installation(): void
    {
        NovaServer::actingAs($this->user)
            ->tool(new OverviewTool(
                app(NovaContext::class),
                app(Schema::class),
                app(Catalog::class),
                $this->catalog(),
            ))
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee(['"uriKey": "users"', 'ada@example.com', 'nova_users_list', '"writes": true']);
    }

    #[Test]
    public function the_resource_schema_exposes_fields_and_their_rules(): void
    {
        NovaServer::actingAs($this->user)
            ->tool(ResourceSchemaTool::class, ['resource' => 'users'])
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee(['"attribute": "email"', '"type": "Text"', 'max:254', '"attribute": "password"']);
    }

    #[Test]
    public function the_resource_schema_rejects_an_unknown_resource(): void
    {
        NovaServer::actingAs($this->user)
            ->tool(ResourceSchemaTool::class, ['resource' => 'aliens'])
            ->assertHasErrors()
            ->assertSee('Unknown resource [aliens]');
    }

    #[Test]
    public function it_lists_records_with_their_resolved_fields(): void
    {
        User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);

        $this->tool('nova_users_list', ['search' => 'Grace'])
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee(['Grace Hopper', 'grace@example.com', '"total": 1']);
    }

    #[Test]
    public function it_reads_a_single_record(): void
    {
        $this->tool('nova_users_get', ['id' => (string) $this->user->getKey()])
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee(['Ada Lovelace', 'ada@example.com', '"update": true']);
    }

    #[Test]
    public function it_creates_a_record_through_nova(): void
    {
        $this->tool('nova_users_create', ['fields' => [
            'name' => 'Katherine Johnson',
            'email' => 'katherine@example.com',
            'password' => 'orbital-mechanics',
        ]])
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee('"created": true');

        $this->assertDatabaseHas('users', ['email' => 'katherine@example.com']);
    }

    #[Test]
    public function it_reports_nova_validation_failures_as_tool_errors(): void
    {
        $this->tool('nova_users_create', ['fields' => ['name' => 'No Email']])
            ->assertHasErrors()
            ->assertSee(['The submitted fields are invalid.', 'email']);

        $this->assertDatabaseMissing('users', ['name' => 'No Email']);
    }

    #[Test]
    public function it_updates_a_record_through_nova(): void
    {
        $this->tool('nova_users_update', [
            'id' => (string) $this->user->getKey(),
            'fields' => ['name' => 'Ada King', 'email' => 'ada@example.com'],
        ])
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee('"updated": true');

        $this->assertSame('Ada King', $this->user->fresh()->name);
    }

    #[Test]
    public function it_deletes_records_through_nova(): void
    {
        $doomed = User::factory()->create();

        $this->tool('nova_users_delete', ['ids' => [(string) $doomed->getKey()]])
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee('"deleted"');

        $this->assertDatabaseMissing('users', ['id' => $doomed->getKey()]);
    }

    #[Test]
    public function it_reads_a_dashboard_and_its_cards(): void
    {
        $this->tool('nova_dashboard_main')
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee(['"dashboard": "main"', '"component": "help-card"']);
    }

    #[Test]
    public function it_reads_the_notification_centre(): void
    {
        $this->tool('nova_notifications')
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee(['"unread": 0', '"notifications": []']);
    }

    /**
     * @return array<int, Tool>
     */
    protected function catalog(): array
    {
        return app(Catalog::class)->tools();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function tool(string $name, array $arguments = []): TestResponse
    {
        $tool = collect($this->catalog())->first(fn (Tool $tool): bool => $tool->name() === $name);

        $this->assertInstanceOf(Tool::class, $tool, "The catalog has no tool named [{$name}].");

        return NovaServer::actingAs($this->user)->tool($tool, $arguments);
    }
}
