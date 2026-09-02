<?php

declare(strict_types=1);

namespace Hemp\NovaMcp\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Nova\Http\Requests\UpdateResourceRequest;

#[IsIdempotent]
class UpdateRecordTool extends ResourceTool
{
    protected static function suffix(): string
    {
        return 'update';
    }

    public function title(): string
    {
        return sprintf('Update %s', $this->singularLabel());
    }

    public function description(): string
    {
        return sprintf(
            'Update one %s record through Nova, running the resource\'s update validation rules and fill callbacks. Only the attributes you send are written. File uploads are not supported.',
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
                ->description(sprintf('Primary key of the %s record to update.', $this->singularLabel())),
            'fields' => $schema->object()->required()
                ->description('Attribute/value map of the fields to change.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'id' => ['required'],
            'fields' => ['required', 'array'],
        ]);

        return $this->guard(fn (): Response => $this->nova->using(
            UpdateResourceRequest::class,
            ['resource' => $this->uriKey(), 'resourceId' => $validated['id']],
            $validated['fields'],
            function (UpdateResourceRequest $novaRequest): Response {
                $model = $novaRequest->findModelQuery()->lockForUpdate()->firstOrFail();

                $model = DB::connection($model->getConnectionName())->transaction(function () use ($novaRequest, $model) {
                    $resource = $novaRequest->newResourceWith($model);

                    $resource->authorizeToUpdate($novaRequest);
                    $resource::validateForUpdate($novaRequest, $resource);

                    [$model, $callbacks] = $resource::fillForUpdate($novaRequest, $model);

                    $resource::beforeUpdate($novaRequest, $model);
                    $model->save();

                    collect($callbacks)->each->__invoke();

                    $resource::afterUpdate($novaRequest, $model);

                    return $model;
                });

                return $this->json([
                    'resource' => $this->uriKey(),
                    'updated' => true,
                    'id' => $model->getKey(),
                ]);
            },
            method: 'POST',
        ));
    }
}
