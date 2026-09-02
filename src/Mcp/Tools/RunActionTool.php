<?php

declare(strict_types=1);

namespace NovaAi\McpTools\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Nova\Http\Requests\ActionRequest;
use NovaAi\McpTools\Nova\NovaContext;
use NovaAi\McpTools\Nova\Schema;

class RunActionTool extends ResourceTool
{
    /**
     * @param  class-string<\Laravel\Nova\Resource>  $resourceClass
     * @param  array<int, array<string, mixed>>  $fields
     */
    public function __construct(
        NovaContext $nova,
        Schema $schema,
        string $resourceClass,
        protected string $actionUriKey,
        protected string $actionName,
        protected bool $standalone,
        protected bool $destructive,
        protected array $fields = [],
    ) {
        parent::__construct($nova, $schema, $resourceClass);

        $this->name = sprintf('%s_%s', $this->name, static::normalize($this->actionUriKey));
    }

    protected static function suffix(): string
    {
        return 'action';
    }

    /**
     * @return array<string, mixed>
     */
    public function annotations(): array
    {
        return ['destructiveHint' => $this->destructive];
    }

    public function title(): string
    {
        return sprintf('%s: %s', $this->label(), $this->actionName);
    }

    public function description(): string
    {
        $description = sprintf(
            'Run the Nova action "%s" on %s records.',
            $this->actionName,
            $this->label(),
        );

        if ($this->standalone) {
            return $description.' This is a standalone action and runs without selecting any records.';
        }

        if ($this->fields !== []) {
            $attributes = implode(', ', array_column($this->fields, 'attribute'));

            return $description.sprintf(' It accepts the fields: %s.', $attributes);
        }

        return $description;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $properties = [
            'fields' => $schema->object()
                ->description('Values for the action\'s own fields, keyed by attribute.'),
        ];

        if ($this->standalone) {
            return $properties;
        }

        return [
            'ids' => $schema->array()->items($schema->string())->min(1)
                ->description('Primary keys of the records to run the action on.'),
            'all' => $schema->boolean()
                ->description('Run the action against every record matching the resource instead of a list of ids.'),
            ...$properties,
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'ids' => ['nullable', 'array'],
            'ids.*' => ['required'],
            'all' => ['nullable', 'boolean'],
            'fields' => ['nullable', 'array'],
        ]);

        $resources = match (true) {
            $this->standalone => '',
            ($validated['all'] ?? false) === true => 'all',
            default => array_values($validated['ids'] ?? []),
        };

        if (! $this->standalone && $resources === []) {
            return $this->error('Provide at least one record id in ids, or set all to true.');
        }

        $parameters = array_merge($validated['fields'] ?? [], ['resources' => $resources]);

        return $this->guard(fn (): Response => $this->nova->using(
            ActionRequest::class,
            ['resource' => $this->uriKey()],
            $parameters,
            function (ActionRequest $novaRequest): Response {
                $novaRequest->validateFields();

                $result = $novaRequest->action()->handleRequest($novaRequest);

                return $this->json([
                    'resource' => $this->uriKey(),
                    'action' => $this->actionUriKey,
                    'result' => $this->result($result),
                ]);
            },
            uri: '/nova-mcp?action='.rawurlencode($this->actionUriKey),
            method: 'POST',
        ));
    }

    protected function result(mixed $result): mixed
    {
        return match (true) {
            $result === null => 'The action completed.',
            $result instanceof JsonSerializable => $result->jsonSerialize(),
            $result instanceof Arrayable => $result->toArray(),
            is_scalar($result) || is_array($result) => $result,
            default => 'The action completed.',
        };
    }
}
