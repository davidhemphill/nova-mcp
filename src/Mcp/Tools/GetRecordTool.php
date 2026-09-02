<?php

declare(strict_types=1);

namespace NovaAi\McpTools\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Nova\Http\Requests\ResourceDetailRequest;

#[IsReadOnly]
#[IsIdempotent]
class GetRecordTool extends ResourceTool
{
    protected static function suffix(): string
    {
        return 'get';
    }

    public function title(): string
    {
        return sprintf('Get %s', $this->singularLabel());
    }

    public function description(): string
    {
        return sprintf(
            'Read one %s record by its key, returning every field Nova shows on its detail screen along with the actions and lenses available for it.',
            $this->singularLabel(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->required()
                ->description(sprintf('Primary key of the %s record.', $this->singularLabel())),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'id' => ['required'],
        ]);

        return $this->guard(fn (): Response => $this->nova->using(
            ResourceDetailRequest::class,
            ['resource' => $this->uriKey(), 'resourceId' => $validated['id']],
            [],
            function (ResourceDetailRequest $novaRequest): Response {
                $resource = $novaRequest->findResourceOrFail();
                $resource->authorizeToView($novaRequest);

                return $this->json([
                    'resource' => $this->uriKey(),
                    ...$this->schema->record($resource, $resource->detailFields($novaRequest)),
                    'authorization' => [
                        'update' => $resource->authorizedToUpdate($novaRequest),
                        'delete' => $resource->authorizedToDelete($novaRequest),
                        'restore' => $this->resourceClass::softDeletes() && $resource->authorizedToRestore($novaRequest),
                        'forceDelete' => $this->resourceClass::softDeletes() && $resource->authorizedToForceDelete($novaRequest),
                    ],
                    'softDeleted' => $resource->isSoftDeleted(),
                    'actions' => $this->schema->actions(
                        $resource->availableActionsOnDetail($novaRequest),
                        $novaRequest,
                    ),
                ]);
            },
        ));
    }
}
