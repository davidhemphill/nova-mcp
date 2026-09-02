<?php

declare(strict_types=1);

namespace Hemp\NovaMcp\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Nova\Http\Requests\DeletionRequest;

/**
 * Shared plumbing for the delete, restore and force delete tools, each of which
 * hands a set of selected ids to one of Nova's own deletion jobs.
 */
abstract class DeletionTool extends ResourceTool
{
    /**
     * The Nova request class the deletion job expects.
     *
     * @return class-string<DeletionRequest>
     */
    abstract protected function requestClass(): string;

    /**
     * Run the Nova job that performs the operation.
     */
    abstract protected function perform(DeletionRequest $request): void;

    /**
     * The past tense verb reported back to the agent.
     */
    abstract protected function verb(): string;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'ids' => $schema->array()->items($schema->string())->min(1)->required()
                ->description(sprintf('Primary keys of the %s records to %s.', $this->label(), $this->verbInfinitive())),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required'],
        ]);

        $requested = array_values($validated['ids']);

        return $this->guard(fn (): Response => $this->nova->using(
            $this->requestClass(),
            ['resource' => $this->uriKey()],
            ['resources' => $requested],
            function (DeletionRequest $novaRequest) use ($requested): Response {
                // Nova drops records the policy denies, and ids that match
                // nothing simply do not appear. Neither is reported back, so
                // the outcome is measured rather than assumed.
                $before = $this->pendingKeys($requested);

                $this->perform($novaRequest);

                $affected = array_values(array_diff($before, $this->pendingKeys($requested)));
                $skipped = array_values(array_diff(
                    array_map(strval(...), $requested),
                    $affected,
                ));

                return $this->json(array_filter([
                    'resource' => $this->uriKey(),
                    $this->verb() => $affected,
                    'skipped' => $skipped,
                    'skippedReason' => $skipped === []
                        ? null
                        : 'Not found, or not permitted by this resource\'s policy.',
                ], static fn (mixed $value): bool => $value !== null));
            },
            method: 'POST',
        ));
    }

    /**
     * Keys among those requested that this operation has not yet been applied to.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    protected function pendingKeys(array $ids): array
    {
        $model = $this->resourceClass::newModel();

        return $this->pendingQuery()
            ->whereKey($ids)
            ->pluck($model->getKeyName())
            ->map(strval(...))
            ->all();
    }

    /**
     * A query over the records still awaiting this operation.
     *
     * @return Builder<Model>
     */
    protected function pendingQuery(): Builder
    {
        return $this->resourceClass::newModel()->newQuery();
    }

    protected function verbInfinitive(): string
    {
        return match ($this->verb()) {
            'deleted' => 'delete',
            'restored' => 'restore',
            default => 'force delete',
        };
    }
}
