<?php

declare(strict_types=1);

namespace NovaAi\McpTools\Nova;

use Illuminate\Support\Collection;
use Laravel\Mcp\Server\Tool;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\DestructiveAction;
use Laravel\Nova\Dashboard;
use Laravel\Nova\Fields\FieldCollection;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Lenses\Lens;
use Laravel\Nova\Nova;
use Laravel\Nova\Resource;
use NovaAi\McpTools\Mcp\Tools\CreateRecordTool;
use NovaAi\McpTools\Mcp\Tools\DashboardTool;
use NovaAi\McpTools\Mcp\Tools\DeleteRecordsTool;
use NovaAi\McpTools\Mcp\Tools\ForceDeleteRecordsTool;
use NovaAi\McpTools\Mcp\Tools\GetRecordTool;
use NovaAi\McpTools\Mcp\Tools\ListRecordsTool;
use NovaAi\McpTools\Mcp\Tools\NotificationsTool;
use NovaAi\McpTools\Mcp\Tools\RestoreRecordsTool;
use NovaAi\McpTools\Mcp\Tools\RunActionTool;
use NovaAi\McpTools\Mcp\Tools\RunLensTool;
use NovaAi\McpTools\Mcp\Tools\UpdateNotificationsTool;
use NovaAi\McpTools\Mcp\Tools\UpdateRecordTool;

/**
 * Turns everything registered with Nova into the MCP tool catalog.
 */
class Catalog
{
    public function __construct(
        protected NovaContext $nova,
        protected Schema $schema,
    ) {
        //
    }

    /**
     * Build one MCP tool per Nova operation the current user is authorized for.
     *
     * @return array<int, Tool>
     */
    public function tools(): array
    {
        return $this->nova->using(
            NovaRequest::class,
            [],
            [],
            fn (NovaRequest $request): array => $this->build($request),
        );
    }

    /**
     * The Nova resources this server publishes.
     *
     * @return array<int, class-string<resource>>
     */
    public function resourceClasses(NovaRequest $request): array
    {
        $only = (array) config('nova-mcp.resources.only', []);
        $except = (array) config('nova-mcp.resources.except', []);

        return Nova::authorizedResources($request)
            ->filter(static fn (string $resourceClass): bool => $only === [] || in_array($resourceClass::uriKey(), $only, true))
            ->reject(static fn (string $resourceClass): bool => in_array($resourceClass::uriKey(), $except, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The dashboards this server publishes.
     *
     * Nova merges dashboards on every "serving" event, so a process that serves
     * more than once accumulates duplicates. Collapse them by URI key.
     *
     * @return Collection<int, Dashboard>
     */
    public function dashboards(NovaRequest $request)
    {
        return collect(Nova::availableDashboards($request))
            ->unique(static fn (Dashboard $dashboard): string => $dashboard->uriKey())
            ->values();
    }

    /**
     * @return array<int, Tool>
     */
    protected function build(NovaRequest $request): array
    {
        $writes = (bool) config('nova-mcp.writes', true);
        $tools = [];

        foreach ($this->resourceClasses($request) as $resourceClass) {
            array_push($tools, ...$this->resourceTools($resourceClass, $request, $writes));
        }

        foreach ($this->dashboards($request) as $dashboard) {
            $tools[] = new DashboardTool(
                $this->nova,
                $this->schema,
                $dashboard->uriKey(),
                (string) $dashboard->name(),
            );
        }

        $tools[] = new NotificationsTool($this->nova, $this->schema);

        if ($writes) {
            $tools[] = new UpdateNotificationsTool($this->nova, $this->schema);
        }

        return $tools;
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @return array<int, Tool>
     */
    protected function resourceTools(string $resourceClass, NovaRequest $request, bool $writes): array
    {
        $tools = [
            new ListRecordsTool($this->nova, $this->schema, $resourceClass),
            new GetRecordTool($this->nova, $this->schema, $resourceClass),
        ];

        if ($writes) {
            if ($resourceClass::authorizedToCreate($request)) {
                $tools[] = new CreateRecordTool($this->nova, $this->schema, $resourceClass);
            }

            $tools[] = new UpdateRecordTool($this->nova, $this->schema, $resourceClass);
            $tools[] = new DeleteRecordsTool($this->nova, $this->schema, $resourceClass);

            if ($resourceClass::softDeletes()) {
                $tools[] = new RestoreRecordsTool($this->nova, $this->schema, $resourceClass);
                $tools[] = new ForceDeleteRecordsTool($this->nova, $this->schema, $resourceClass);
            }
        }

        $resource = $resourceClass::newResource();

        foreach ($resource->availableLenses($request) as $lens) {
            /** @var Lens $lens */
            $tools[] = new RunLensTool(
                $this->nova,
                $this->schema,
                $resourceClass,
                $lens->uriKey(),
                (string) $lens->name(),
            );
        }

        if (config('nova-mcp.actions', true)) {
            foreach ($resource->availableActions($request) as $action) {
                /** @var Action $action */
                $tools[] = new RunActionTool(
                    $this->nova,
                    $this->schema,
                    $resourceClass,
                    $action->uriKey(),
                    (string) $action->name(),
                    $action->isStandalone(),
                    $action instanceof DestructiveAction,
                    $this->schema->fields(FieldCollection::make($action->fields($request)), $request),
                );
            }
        }

        return $tools;
    }
}
