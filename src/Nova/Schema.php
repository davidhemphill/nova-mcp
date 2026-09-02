<?php

declare(strict_types=1);

namespace NovaAi\McpTools\Nova;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\DestructiveAction;
use Laravel\Nova\Card;
use Laravel\Nova\Contracts\ListableField;
use Laravel\Nova\Contracts\RelatableField;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\FieldCollection;
use Laravel\Nova\Fields\Repeater;
use Laravel\Nova\Fields\Stack;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Lenses\Lens;
use Laravel\Nova\Metrics\Metric;
use Laravel\Nova\Resource;
use Laravel\Nova\ResourceToolElement;
use Stringable;
use Throwable;

/**
 * Turns Nova's own objects into the compact JSON an agent can reason about.
 */
class Schema
{
    /**
     * Whether the field being serialized has rules that need a record.
     */
    protected bool $contextual = false;

    /**
     * Summarize a resource without resolving any of its fields.
     *
     * @param  class-string<resource>  $resourceClass
     * @return array<string, mixed>
     */
    public function resourceSummary(string $resourceClass, NovaRequest $request): array
    {
        return [
            'uriKey' => $resourceClass::uriKey(),
            'label' => $this->text($resourceClass::label()),
            'singularLabel' => $this->text($resourceClass::singularLabel()),
            'group' => $this->text($resourceClass::group()),
            'model' => $resourceClass::$model,
            'softDeletes' => $resourceClass::softDeletes(),
            'searchable' => $resourceClass::searchable(),
            'searchableColumns' => array_values(array_filter(
                $resourceClass::searchableColumns(),
                'is_string',
            )),
            'perPageOptions' => $resourceClass::perPageOptions(),
            'authorization' => [
                'viewAny' => $resourceClass::authorizedToViewAny($request),
                'create' => $resourceClass::authorizedToCreate($request),
            ],
        ];
    }

    /**
     * Describe everything an agent needs to work with a resource.
     *
     * @param  class-string<resource>  $resourceClass
     * @return array<string, mixed>
     */
    public function resource(string $resourceClass, NovaRequest $request): array
    {
        $resource = $resourceClass::newResource();

        return array_merge($this->resourceSummary($resourceClass, $request), [
            'fields' => [
                'index' => $this->fields($resource->indexFields($request), $request),
                'detail' => $this->fields($resource->detailFields($request), $request),
                'create' => $this->fields($resource->creationFields($request), $request),
                'update' => $this->fields($resource->updateFields($request), $request),
            ],
            'filters' => $this->filters($resource->availableFilters($request)),
            'lenses' => $this->lenses($resource->availableLenses($request)),
            'actions' => $this->actions($resource->availableActions($request), $request),
            'cards' => $this->cards($resource->availableCards($request)),
            'tools' => $this->resourceTools($resource->availableFields($request)),
        ]);
    }

    /**
     * @param  FieldCollection<int, mixed>  $fields
     * @return array<int, array<string, mixed>>
     */
    public function fields(FieldCollection $fields, NovaRequest $request): array
    {
        return $fields
            ->filter(static fn (mixed $field): bool => $field instanceof Field)
            ->map(fn (Field $field): array => $this->field($field, $request))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function field(Field $field, NovaRequest $request): array
    {
        $this->contextual = false;

        $schema = array_filter([
            'attribute' => $field->attribute,
            'name' => $this->text($field->name),
            'type' => class_basename($field),
            'component' => $field->component(),
            'help' => $this->text($field->getHelpText()),
            'panel' => $this->text($field->panel?->name),
            'nullable' => $field->nullable,
            'sortable' => $field->sortable,
            'readonly' => $field->isReadonly($request),
            'required' => $field->isRequired($request),
            'rules' => $this->resolveRules($field, 'getRules', 'rules', $request),
            'creationRules' => $this->resolveRules($field, 'getCreationRules', 'creationRules', $request),
            'updateRules' => $this->resolveRules($field, 'getUpdateRules', 'updateRules', $request),
            'contextualRules' => $this->contextual,
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== false);

        if ($field instanceof Stack) {
            $schema['composes'] = $field->fields()
                ->filter(static fn (mixed $line): bool => $line instanceof Field)
                ->map(fn (Field $line): array => [
                    'attribute' => $line->attribute,
                    'name' => $this->text($line->name),
                    'type' => class_basename($line),
                ])
                ->values()
                ->all();
        }

        if ($field instanceof RelatableField) {
            $schema['relationship'] = array_filter([
                'type' => $field->relationshipType(),
                'name' => $field->relationshipName(),
                'resource' => $field->resourceName,
                'listable' => $field instanceof ListableField,
            ]);
        }

        $meta = $field->meta();

        foreach (['options', 'trueValue', 'falseValue', 'min', 'max', 'step'] as $key) {
            if (array_key_exists($key, $meta)) {
                $schema[$key] = $meta[$key];
            }
        }

        return $schema;
    }

    /**
     * @param  Collection<int, Filter>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function filters(Collection $filters): array
    {
        return $filters->map(static fn (Filter $filter): array => [
            'key' => $filter->key(),
            'name' => (string) $filter->name(),
            'component' => $filter->component(),
            'default' => $filter->default(),
            'options' => $filter->jsonSerialize()['options'],
        ])->values()->all();
    }

    /**
     * @param  Collection<int, Lens>  $lenses
     * @return array<int, array<string, mixed>>
     */
    public function lenses(Collection $lenses): array
    {
        return $lenses->map(static fn (Lens $lens): array => [
            'uriKey' => $lens->uriKey(),
            'name' => (string) $lens->name(),
        ])->values()->all();
    }

    /**
     * @param  Collection<int, Action>  $actions
     * @return array<int, array<string, mixed>>
     */
    public function actions(Collection $actions, NovaRequest $request): array
    {
        return $actions->map(fn (Action $action): array => [
            'uriKey' => $action->uriKey(),
            'name' => (string) $action->name(),
            'destructive' => $action instanceof DestructiveAction,
            'standalone' => $action->isStandalone(),
            'fields' => $this->fields(
                FieldCollection::make($action->fields($request)),
                $request,
            ),
        ])->values()->all();
    }

    /**
     * @param  Collection<int, Card>  $cards
     * @return array<int, array<string, mixed>>
     */
    public function cards(Collection $cards): array
    {
        return $cards->map(static fn (mixed $card): array => array_filter([
            'uriKey' => method_exists($card, 'uriKey') ? $card->uriKey() : null,
            'name' => method_exists($card, 'name') ? (string) $card->name() : null,
            'component' => $card->component(),
            'metric' => $card instanceof Metric,
        ], static fn (mixed $value): bool => $value !== null))->values()->all();
    }

    /**
     * Nova resource tools are panels rendered on the detail screen.
     *
     * @param  FieldCollection<int, mixed>  $fields
     * @return array<int, array<string, mixed>>
     */
    public function resourceTools(FieldCollection $fields): array
    {
        return $fields
            ->filter(static fn (mixed $field): bool => $field instanceof ResourceToolElement)
            ->map(static fn (ResourceToolElement $tool): array => [
                'name' => (string) ($tool->panel?->name ?? $tool->component()),
                'component' => $tool->component(),
            ])
            ->values()
            ->all();
    }

    /**
     * Reduce a resolved resource to its identifier, title and field values.
     *
     * @param  FieldCollection<int, mixed>  $fields
     * @return array<string, mixed>
     */
    public function record(Resource $resource, FieldCollection $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            if (! $field instanceof Field || $field->attribute === null) {
                continue;
            }

            $values[$field->attribute] = match (true) {
                $field instanceof Stack => $this->stackValues($field),
                $field instanceof Repeater => $this->repeaterValues($field),
                $field instanceof ListableField => $this->relationSummary($field, $resource),
                default => $this->value($field->value),
            };
        }

        return [
            'id' => $resource->model()?->getKey(),
            'title' => $this->text($resource->title()),
            'fields' => $values,
        ];
    }

    /**
     * Read the rows out of a Repeater.
     *
     * The field resolves to a collection of Repeatable objects whose own
     * jsonSerialize() returns the editor's configuration — icons, labels and
     * field definitions — rather than the stored content. Each repeatable has
     * to be asked to resolve its fields before the row's values are readable.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function repeaterValues(Repeater $repeater): array
    {
        if (! $repeater->value instanceof Collection) {
            return [];
        }

        $request = Container::getInstance()->make(NovaRequest::class);

        return $repeater->value->map(function (mixed $repeatable) use ($request): array {
            $fields = [];

            foreach ($repeatable->resolveFieldsForDisplay($request) as $field) {
                if ($field instanceof Field && $field->attribute !== null) {
                    $fields[$field->attribute] = $this->value($field->value);
                }
            }

            return ['type' => $repeatable->key(), 'fields' => $fields];
        })->values()->all();
    }

    /**
     * Describe a to-many relationship.
     *
     * Nova never resolves these inline — the UI loads them as their own
     * paginated table — so the field's value is always null. Emitting that null
     * would read as "this record has none", which is worse than saying nothing.
     * Report what is on the other side and how many, so the caller knows to go
     * and fetch them with that resource's own tools.
     *
     * @return array<string, mixed>
     */
    protected function relationSummary(Field&ListableField $field, Resource $resource): array
    {
        $summary = array_filter([
            'type' => $field->relationshipType(),
            'resource' => $field->resourceName,
        ]);

        try {
            $model = $resource->model();
            $relation = $field->relationshipName();

            if ($model !== null && method_exists($model, $relation)) {
                $summary['count'] = $model->{$relation}()->count();
            }
        } catch (Throwable) {
            // A relation that cannot be counted is still worth naming.
        }

        return $summary;
    }

    /**
     * Read the values out of a Stack.
     *
     * A Stack composes other fields and holds no value of its own, so reading
     * `$field->value` yields null and silently drops everything it displays.
     * Its lines are already resolved by the time the row is serialized.
     *
     * @return array<string, mixed>
     */
    protected function stackValues(Stack $stack): array
    {
        $values = [];

        foreach ($stack->fields() as $line) {
            if (! $line instanceof Field || $line->attribute === null) {
                continue;
            }

            $values[$line->attribute] = $this->value($line->value);
        }

        return $values;
    }

    /**
     * Resolve one set of validation rules, tolerating record-scoped rules.
     *
     * Nova lets a field declare its rules as a closure, and some of those
     * closures assume a request scoped to an existing record. BelongsToMany's
     * `noDuplicateRelations()` is the built-in example: it builds a NotAttached
     * rule from `$request->findModelOrFail()`. A resource-level schema has no
     * such record, so fall back to the statically declared rules and flag the
     * field, rather than failing the whole description.
     *
     * @return array<int, string>
     */
    protected function resolveRules(Field $field, string $method, string $property, NovaRequest $request): array
    {
        try {
            return $this->rules($field->{$method}($request));
        } catch (Throwable) {
            $this->contextual = true;

            $declared = $field->{$property};

            return is_array($declared) ? $this->rules([$declared]) : [];
        }
    }

    /**
     * Flatten Nova's `[attribute => rules]` map into a list of readable rules.
     *
     * @param  array<string, mixed>  $rules
     * @return array<int, string>
     */
    protected function rules(array $rules): array
    {
        return collect(Arr::flatten($rules, 1))
            ->flatMap(static fn (mixed $rule): array => is_string($rule) ? explode('|', $rule) : [$rule])
            ->map(static fn (mixed $rule): string => match (true) {
                is_string($rule) => $rule,
                $rule instanceof Closure => 'closure',
                is_object($rule) => class_basename($rule),
                default => (string) json_encode($rule),
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return scalar|array<array-key, mixed>|null
     */
    protected function value(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof Stringable => (string) $value,
            $value instanceof \JsonSerializable => $value->jsonSerialize(),
            is_object($value) => method_exists($value, '__toString') ? (string) $value : null,
            default => $value,
        };
    }

    protected function text(Stringable|string|null $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
