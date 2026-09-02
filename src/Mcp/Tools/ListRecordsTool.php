<?php

declare(strict_types=1);

namespace NovaAi\McpTools\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Nova\Http\Requests\ResourceIndexRequest;

#[IsReadOnly]
#[IsIdempotent]
class ListRecordsTool extends ResourceTool
{
    protected static function suffix(): string
    {
        return 'list';
    }

    public function title(): string
    {
        return sprintf('List %s', $this->label());
    }

    public function description(): string
    {
        return sprintf(
            'List %s records from Nova, applying the same search, filters, sorting and authorization as the Nova index screen. Call nova_resource_schema with resource "%s" for the available fields, filters and sort keys.',
            $this->label(),
            $this->uriKey(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()
                ->description('Search term matched against the resource\'s searchable columns.'),
            'filters' => $schema->object()
                ->description('Nova filters as a {"Filter\\Class\\Name": value} map. Filter class names come from nova_resource_schema.'),
            'orderBy' => $schema->string()
                ->description('Attribute of a sortable field to order by.'),
            'orderByDirection' => $schema->string()->enum(['asc', 'desc'])
                ->description('Sort direction. Defaults to Nova\'s own default ordering.'),
            'page' => $schema->integer()->min(1)->description('Page number, starting at 1.'),
            'perPage' => $schema->integer()->min(1)
                ->description('Page size. Nova clamps this to the resource\'s perPageOptions.'),
            'trashed' => $schema->string()->enum(['with', 'only'])
                ->description('Include soft deleted records ("with") or return only them ("only").'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'filters' => ['nullable', 'array'],
            'orderBy' => ['nullable', 'string', 'max:255'],
            'orderByDirection' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1'],
            'trashed' => ['nullable', 'in:with,only'],
        ]);

        $page = (int) ($validated['page'] ?? 1);

        $parameters = array_filter([
            'search' => $validated['search'] ?? null,
            'filters' => isset($validated['filters']) ? $this->encodeFilters($validated['filters']) : null,
            'orderBy' => $validated['orderBy'] ?? null,
            'orderByDirection' => $validated['orderByDirection'] ?? null,
            'perPage' => $this->perPage($validated['perPage'] ?? null),
            'trashed' => $validated['trashed'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        return $this->guard(fn (): Response => $this->nova->using(
            ResourceIndexRequest::class,
            ['resource' => $this->uriKey()],
            $parameters,
            fn (ResourceIndexRequest $novaRequest): Response => $this->onPage(
                $page,
                fn (): Response => $this->list($novaRequest, $page),
            ),
        ));
    }

    protected function list(ResourceIndexRequest $novaRequest, int $page): Response
    {
        abort_unless($this->resourceClass::authorizedToViewAny($novaRequest), 403);

        [$paginator, $total] = $novaRequest->searchIndex();

        $records = $paginator->getCollection()
            ->map(fn (mixed $model): array => $this->schema->record(
                $resource = new $this->resourceClass($model),
                $resource->indexFields($novaRequest),
            ))
            ->values()
            ->all();

        return $this->json([
            'resource' => $this->uriKey(),
            'page' => $page,
            'perPage' => $paginator->perPage(),
            'total' => $total,
            'hasMorePages' => $paginator->hasMorePages(),
            'records' => $records,
        ]);
    }
}
