<?php

namespace Hemp\NovaMcp\Tests\Feature;

use Hemp\NovaMcp\Mcp\NovaServer;
use Hemp\NovaMcp\Mcp\Tools\ResourceSchemaTool;
use Hemp\NovaMcp\Nova\Catalog;
use Hemp\NovaMcp\Tests\Fixtures\Models\Article;
use Hemp\NovaMcp\Tests\Fixtures\Models\Tag;
use Hemp\NovaMcp\Tests\Fixtures\Models\User;
use Hemp\NovaMcp\Tests\Fixtures\Nova\ContentBlock;
use Hemp\NovaMcp\Tests\Fixtures\Nova\NameStartsWith;
use Hemp\NovaMcp\Tests\Fixtures\Nova\Person;
use Hemp\NovaMcp\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ExecuteTools;
use Laravel\Mcp\Server\Tools\SearchTools;
use Laravel\Mcp\Server\Tools\ToolSearch;
use Laravel\Nova\Nova;
use PHPUnit\Framework\Attributes\Test;

/**
 * Covers the parts of the catalog the application's own resources do not reach:
 * filters, lenses, actions and the tool search fallback.
 */
class NovaMcpCatalogSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Ada Lovelace']);

        $this->actingAs($this->user);

        Nova::serving(static fn () => Nova::resources([Person::class]));

        // Most of these tests call catalog tools by name, so keep the server in
        // its direct-listing mode; the tool search test overrides this itself.
        config()->set('nova-mcp.tool_search_threshold', 1000);
    }

    #[Test]
    public function it_builds_a_tool_for_every_lens_and_action(): void
    {
        $names = collect($this->catalog())->map(fn (Tool $tool): string => $tool->name())->all();

        $this->assertContains('nova_people_lens_recent_people', $names);
        $this->assertContains('nova_people_action_rename', $names);
    }

    #[Test]
    public function the_resource_schema_reports_filters_lenses_and_actions(): void
    {
        NovaServer::actingAs($this->user)
            ->tool(ResourceSchemaTool::class, ['resource' => 'people'])
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee([
                'NameStartsWith',
                '"name": "Name Starts With"',
                '"uriKey": "recent-people"',
                '"uriKey": "rename"',
            ]);
    }

    #[Test]
    public function listing_applies_a_nova_filter(): void
    {
        User::factory()->create(['name' => 'Grace Hopper']);

        $this->tool('nova_people_list', ['filters' => [NameStartsWith::class => 'G']])
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee(['Grace Hopper', '"total": 1']);
    }

    #[Test]
    public function listing_reads_the_values_composed_inside_a_stack(): void
    {
        // A Stack holds no value of its own, so without unpacking its lines the
        // field serializes as null and the email is lost from the row entirely.
        $this->tool('nova_people_list', ['perPage' => 25])
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee(['"identity"', 'Ada Lovelace', $this->user->email])
            ->assertDontSee('"identity": null');
    }

    #[Test]
    public function the_schema_says_what_a_stack_composes(): void
    {
        NovaServer::actingAs($this->user)
            ->tool(ResourceSchemaTool::class, ['resource' => 'people'])
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee(['"composes"', '"attribute": "name"', '"attribute": "email"']);
    }

    #[Test]
    public function reading_a_record_summarises_its_to_many_relations(): void
    {
        $post = Article::factory()->for($this->user)->create();
        $post->tags()->attach(Tag::factory()->count(3)->create());

        $this->tool('nova_articles_get', ['id' => $post->getKey()])
            ->assertOk()
            ->assertHasNoErrors()
            // Nova never resolves a to-many inline, so the raw value is null.
            // Reporting that would read as "this post has no tags".
            ->assertDontSee('"tags": null')
            ->assertSee(['"belongsToMany"', '"resource": "tags"', '"count": 3']);
    }

    #[Test]
    public function reading_a_record_unpacks_repeater_rows(): void
    {
        $post = Article::factory()->for($this->user)->create([
            'blocks' => [[
                'type' => (new ContentBlock)->key(),
                'fields' => ['heading' => 'Chapter one', 'copy' => 'Body text here.'],
            ]],
        ]);

        $this->tool('nova_articles_get', ['id' => $post->getKey()])
            ->assertOk()
            ->assertHasNoErrors()
            // A Repeatable serializes its editor configuration by default.
            ->assertDontSee('singularLabel')
            ->assertSee(['"type": "content-block"', 'Chapter one', 'Body text here.']);
    }

    #[Test]
    public function deleting_reports_only_the_records_it_touched(): void
    {
        $person = User::factory()->create();

        // Nova drops records the policy denies and ids that match nothing, and
        // reports neither, so echoing the request would claim a phantom delete.
        $this->tool('nova_people_delete', ['ids' => [(string) $person->getKey(), '999999']])
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee(['"deleted"', (string) $person->getKey(), '"skipped"', '999999'])
            ->assertDontSee('"deleted": []');

        $this->assertNull(User::find($person->getKey()));
    }

    #[Test]
    public function deleting_a_record_that_does_not_exist_reports_nothing_deleted(): void
    {
        $this->tool('nova_people_delete', ['ids' => ['999999']])
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee(['"deleted": []', '"skipped"', 'not permitted']);
    }

    #[Test]
    public function it_runs_a_lens(): void
    {
        User::factory()->create(['name' => 'Grace Hopper']);

        $this->tool('nova_people_lens_recent_people')
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee(['"lens": "recent-people"', 'Grace Hopper']);
    }

    #[Test]
    public function it_runs_an_action_with_its_own_fields(): void
    {
        $this->tool('nova_people_action_rename', [
            'ids' => [(string) $this->user->getKey()],
            'fields' => ['name' => 'Ada King'],
        ])
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee('Renamed 1 records.');

        $this->assertSame('Ada King', $this->user->fresh()->name);
    }

    #[Test]
    public function it_validates_an_actions_own_fields(): void
    {
        $this->tool('nova_people_action_rename', [
            'ids' => [(string) $this->user->getKey()],
            'fields' => [],
        ])->assertHasErrors()->assertSee('The submitted fields are invalid.');

        $this->assertSame('Ada Lovelace', $this->user->fresh()->name);
    }

    #[Test]
    public function it_requires_records_for_a_non_standalone_action(): void
    {
        $this->tool('nova_people_action_rename', ['fields' => ['name' => 'Nobody']])
            ->assertHasErrors()
            ->assertSee('Provide at least one record id in ids');
    }

    #[Test]
    public function large_catalogs_are_published_behind_tool_search(): void
    {
        config()->set('nova-mcp.tool_search_threshold', 0);

        NovaServer::actingAs($this->user)
            ->tool(new SearchTools(new ToolSearch([])), ['query' => 'people list'])
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee('nova_people_list');

        NovaServer::actingAs($this->user)
            ->tool(new ExecuteTools(new ToolSearch([]), 25), [
                'calls' => [['name' => 'nova_people_list', 'arguments' => ['search' => 'Ada']]],
            ])
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee('Ada Lovelace');
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
