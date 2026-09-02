<?php

declare(strict_types=1);

namespace NovaAi\McpTools\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Nova\Dashboard;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Notifications\Notification;
use Laravel\Nova\Nova;
use NovaAi\McpTools\Mcp\Contracts\DescribesNovaSubject;
use NovaAi\McpTools\Nova\Catalog;
use NovaAi\McpTools\Nova\NovaContext;
use NovaAi\McpTools\Nova\Schema;
use Throwable;

#[IsReadOnly]
#[IsIdempotent]
class OverviewTool extends NovaTool
{
    protected string $name = 'nova_overview';

    protected string $title = 'Nova Overview';

    /**
     * @param  array<int, Tool>  $catalogTools
     */
    public function __construct(
        NovaContext $nova,
        Schema $schema,
        protected Catalog $catalog,
        protected array $catalogTools = [],
    ) {
        parent::__construct($nova, $schema);
    }

    public function description(): string
    {
        return 'Map this Laravel Nova installation: who the server is acting as, every resource with its label, model and authorization, every dashboard, the unread notification count, and the name of every tool available for each of them. Call this first, then nova_resource_schema for the resource you need.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        return $this->guard(fn (): Response => $this->nova->using(
            NovaRequest::class,
            [],
            [],
            function (NovaRequest $novaRequest): Response {
                $user = $novaRequest->user();
                $toolNames = $this->toolNames();

                return $this->json([
                    'nova' => [
                        'name' => (string) Nova::name(),
                        'version' => Nova::version(),
                        'path' => config('nova.path'),
                    ],
                    'actingAs' => array_filter([
                        'id' => $user?->getAuthIdentifier(),
                        'name' => $user->name ?? null,
                        'email' => $user->email ?? null,
                    ], static fn (mixed $value): bool => $value !== null),
                    'capabilities' => [
                        'writes' => (bool) config('nova-mcp.writes', true),
                        'actions' => (bool) config('nova-mcp.actions', true),
                    ],
                    'resources' => collect($this->catalog->resourceClasses($novaRequest))
                        ->map(fn (string $resourceClass): array => [
                            ...$this->schema->resourceSummary($resourceClass, $novaRequest),
                            'tools' => $toolNames['resource'][$resourceClass::uriKey()] ?? [],
                        ])
                        ->values()
                        ->all(),
                    'dashboards' => $this->catalog->dashboards($novaRequest)
                        ->map(static fn (Dashboard $dashboard): array => [
                            'uriKey' => $dashboard->uriKey(),
                            'name' => (string) $dashboard->name(),
                        ])
                        ->values()
                        ->all(),
                    'dashboardTools' => $toolNames['dashboard'] ?? [],
                    'notifications' => [
                        'unread' => $this->unreadCount($novaRequest),
                        'tools' => $toolNames['notifications']['notifications'] ?? [],
                    ],
                ]);
            },
        ));
    }

    /**
     * Group the catalog's tool names by the Nova object they act on.
     *
     * @return array<string, array<string, array<int, string>>>
     */
    protected function toolNames(): array
    {
        $grouped = [];

        foreach ($this->catalogTools as $tool) {
            if (! $tool instanceof DescribesNovaSubject) {
                continue;
            }

            ['type' => $type, 'key' => $key] = $tool->subject();

            $grouped[$type][$key][] = $tool->name();
        }

        return $grouped;
    }

    protected function unreadCount(NovaRequest $request): ?int
    {
        try {
            return Notification::query()->currentUserFromRequest($request)->whereNull('read_at')->count();
        } catch (Throwable) {
            return null;
        }
    }
}
