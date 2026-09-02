<?php

declare(strict_types=1);

namespace NovaAi\McpTools\Mcp\Tools;

use Laravel\Nova\Resource;
use NovaAi\McpTools\Mcp\Contracts\DescribesNovaSubject;
use NovaAi\McpTools\Nova\NovaContext;
use NovaAi\McpTools\Nova\Schema;

/**
 * Base for the tools that are generated once per Nova resource.
 */
abstract class ResourceTool extends NovaTool implements DescribesNovaSubject
{
    /**
     * @param  class-string<resource>  $resourceClass
     */
    public function __construct(
        NovaContext $nova,
        Schema $schema,
        protected string $resourceClass,
    ) {
        parent::__construct($nova, $schema);

        $this->name = static::nameFor($this->resourceClass);
    }

    /**
     * The suffix appended to the resource's URI key to form the tool name.
     */
    abstract protected static function suffix(): string;

    /**
     * @param  class-string<resource>  $resourceClass
     */
    public static function nameFor(string $resourceClass): string
    {
        return sprintf('nova_%s_%s', static::normalize($resourceClass::uriKey()), static::suffix());
    }

    /**
     * @return array{type: string, key: string}
     */
    public function subject(): array
    {
        return ['type' => 'resource', 'key' => $this->uriKey()];
    }

    public static function normalize(string $value): string
    {
        return trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $value), '_');
    }

    protected function uriKey(): string
    {
        return $this->resourceClass::uriKey();
    }

    protected function label(): string
    {
        return (string) $this->resourceClass::label();
    }

    protected function singularLabel(): string
    {
        return (string) $this->resourceClass::singularLabel();
    }
}
