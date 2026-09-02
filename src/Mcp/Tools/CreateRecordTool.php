<?php

declare(strict_types=1);

namespace NovaAi\McpTools\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Nova\Http\Requests\CreateResourceRequest;

class CreateRecordTool extends ResourceTool
{
    protected static function suffix(): string
    {
        return 'create';
    }

    public function title(): string
    {
        return sprintf('Create %s', $this->singularLabel());
    }

    public function description(): string
    {
        return sprintf(
            'Create a %s record through Nova, running the resource\'s creation validation rules and fill callbacks. Call nova_resource_schema with resource "%s" first to learn the writable attributes and their rules. File uploads are not supported.',
            $this->singularLabel(),
            $this->uriKey(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'fields' => $schema->object()->required()
                ->description('Attribute/value map matching the resource\'s creation fields.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'fields' => ['required', 'array'],
        ]);

        return $this->guard(fn (): Response => $this->nova->using(
            CreateResourceRequest::class,
            ['resource' => $this->uriKey()],
            $validated['fields'],
            function (CreateResourceRequest $novaRequest): Response {
                $resourceClass = $this->resourceClass;

                $resourceClass::authorizeToCreate($novaRequest);
                $resourceClass::validateForCreation($novaRequest);

                $model = DB::connection($resourceClass::newModel()->getConnectionName())
                    ->transaction(function () use ($novaRequest, $resourceClass) {
                        [$model, $callbacks] = $resourceClass::fill($novaRequest, $resourceClass::newModel());

                        $resourceClass::beforeCreate($novaRequest, $model);
                        $model->save();

                        collect($callbacks)->each->__invoke();

                        $resourceClass::afterCreate($novaRequest, $model);

                        return $model;
                    });

                return $this->json([
                    'resource' => $this->uriKey(),
                    'created' => true,
                    'id' => $model->getKey(),
                ]);
            },
            method: 'POST',
        ));
    }
}
