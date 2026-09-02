<?php

declare(strict_types=1);

namespace Hemp\NovaMcp\Mcp\Tools;

use Hemp\NovaMcp\Nova\NovaContext;
use Hemp\NovaMcp\Nova\Schema;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\LensRequest;

#[IsReadOnly]
#[IsIdempotent]
class RunLensTool extends ResourceTool
{
    /**
     * @param  class-string<\Laravel\Nova\Resource>  $resourceClass
     */
    public function __construct(
        NovaContext $nova,
        Schema $schema,
        string $resourceClass,
        protected string $lensUriKey,
        protected string $lensName,
    ) {
        parent::__construct($nova, $schema, $resourceClass);

        $this->name = sprintf('%s_%s', $this->name, static::normalize($this->lensUriKey));
    }

    protected static function suffix(): string
    {
        return 'lens';
    }

    public function title(): string
    {
        return sprintf('%s: %s', $this->label(), $this->lensName);
    }

    public function description(): string
    {
        return sprintf(
            'Run the Nova lens "%s" over %s, returning the rows and columns the lens defines rather than the resource\'s own index fields.',
            $this->lensName,
            $this->label(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Search term, if the lens is searchable.'),
            'filters' => $schema->object()->description('Nova filters as a {"Filter\\Class\\Name": value} map.'),
            'orderBy' => $schema->string()->description('Attribute of a lens field to order by.'),
            'orderByDirection' => $schema->string()->enum(['asc', 'desc'])->description('Sort direction.'),
            'page' => $schema->integer()->min(1)->description('Page number, starting at 1.'),
            'perPage' => $schema->integer()->min(1)
                ->description('Page size. Nova clamps this to the lens or resource perPageOptions.'),
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
        ]);

        $page = (int) ($validated['page'] ?? 1);

        $parameters = array_filter([
            'search' => $validated['search'] ?? null,
            'filters' => isset($validated['filters']) ? $this->encodeFilters($validated['filters']) : null,
            'orderBy' => $validated['orderBy'] ?? null,
            'orderByDirection' => $validated['orderByDirection'] ?? null,
            'perPage' => $this->perPage($validated['perPage'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);

        return $this->guard(fn (): Response => $this->nova->using(
            LensRequest::class,
            ['resource' => $this->uriKey(), 'lens' => $this->lensUriKey],
            $parameters,
            fn (LensRequest $novaRequest): Response => $this->onPage(
                $page,
                fn (): Response => $this->run($novaRequest, $page),
            ),
        ));
    }

    protected function run(LensRequest $novaRequest, int $page): Response
    {
        $lens = $novaRequest->lens();

        $query = $novaRequest->newSearchQuery();

        if ($novaRequest->resourceSoftDeletes()) {
            $novaRequest->trashed()->applySoftDeleteConstraint($query);
        }

        $paginator = $lens->query($novaRequest, $query);

        if ($paginator instanceof Builder) {
            $paginator = $paginator->simplePaginate($novaRequest->perPage());
        }

        $records = $novaRequest->toResources($paginator->getCollection())
            ->map(static fn (array $row): array => [
                'id' => $row['id']->value ?? null,
                'title' => isset($row['title']) ? (string) $row['title'] : null,
                'fields' => collect($row['fields'] ?? [])
                    ->filter(static fn (mixed $field): bool => $field instanceof Field && $field->attribute !== null)
                    ->mapWithKeys(static fn (Field $field): array => [$field->attribute => $field->value])
                    ->all(),
            ])
            ->values()
            ->all();

        return $this->json([
            'resource' => $this->uriKey(),
            'lens' => $this->lensUriKey,
            'name' => (string) $lens->name(),
            'page' => $page,
            'perPage' => $paginator->perPage(),
            'hasMorePages' => $paginator->hasMorePages(),
            'records' => $records,
        ]);
    }
}
