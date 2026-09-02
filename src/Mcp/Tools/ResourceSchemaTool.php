<?php

declare(strict_types=1);

namespace NovaAi\McpTools\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use NovaAi\McpTools\Nova\Catalog;
use NovaAi\McpTools\Nova\NovaContext;
use NovaAi\McpTools\Nova\Schema;

#[IsReadOnly]
#[IsIdempotent]
class ResourceSchemaTool extends NovaTool
{
    protected string $name = 'nova_resource_schema';

    protected string $title = 'Nova Resource Schema';

    public function __construct(
        NovaContext $nova,
        Schema $schema,
        protected Catalog $catalog,
    ) {
        parent::__construct($nova, $schema);
    }

    public function description(): string
    {
        return 'Describe a Nova resource in full: its index, detail, creation and update fields with validation rules and options, its filters and their allowed values, its lenses, actions, cards and resource tools. Read this before listing, creating or updating records.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->required()
                ->description('URI key of the Nova resource, as returned by nova_overview.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'resource' => ['required', 'string'],
        ]);

        return $this->guard(fn (): Response => $this->nova->using(
            NovaRequest::class,
            ['resource' => $validated['resource']],
            [],
            function (NovaRequest $novaRequest) use ($validated): Response {
                $resourceClass = Nova::resourceForKey($validated['resource']);

                if ($resourceClass === null || ! in_array($resourceClass, $this->catalog->resourceClasses($novaRequest), true)) {
                    return $this->error(sprintf(
                        'Unknown resource [%s]. Call nova_overview for the resources this server exposes.',
                        $validated['resource'],
                    ));
                }

                return $this->json($this->schema->resource($resourceClass, $novaRequest));
            },
        ));
    }
}
