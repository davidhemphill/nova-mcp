<?php

declare(strict_types=1);

namespace NovaAi\McpTools\Mcp\Contracts;

/**
 * Implemented by catalog tools so the overview can group them by what they act on.
 */
interface DescribesNovaSubject
{
    /**
     * @return array{type: string, key: string}
     */
    public function subject(): array;
}
