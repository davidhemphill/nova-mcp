<?php

declare(strict_types=1);

namespace NovaAi\McpTools\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Laravel\Mcp\Server\Tool;
use NovaAi\McpTools\Mcp\Contracts\DescribesNovaSubject;
use NovaAi\McpTools\Nova\Catalog;

/**
 * Backs the tool's Nova screen: the same catalog the MCP server publishes.
 */
class CatalogController extends Controller
{
    public function __invoke(Catalog $catalog): JsonResponse
    {
        $tools = collect($catalog->tools())
            ->map(static fn (Tool $tool): array => [
                'name' => $tool->name(),
                'title' => $tool->title(),
                'description' => $tool->description(),
                'subject' => $tool instanceof DescribesNovaSubject ? $tool->subject() : null,
                'annotations' => $tool->annotations(),
            ])
            ->values();

        return response()->json([
            'server' => [
                'name' => config('nova-mcp.name'),
                'version' => config('nova-mcp.version'),
            ],
            'transports' => [
                'web' => [
                    'enabled' => (bool) config('nova-mcp.web.enabled'),
                    'url' => url((string) config('nova-mcp.web.route')),
                ],
                'local' => [
                    'enabled' => (bool) config('nova-mcp.local.enabled'),
                    'handle' => config('nova-mcp.local.handle'),

                    // Never the token itself, only whether one is configured.
                    'configured' => filled(config('nova-mcp.token')),
                ],
            ],
            'capabilities' => [
                'writes' => (bool) config('nova-mcp.writes'),
                'actions' => (bool) config('nova-mcp.actions'),
            ],
            'alwaysAvailable' => ['nova_overview', 'nova_resource_schema', 'search_tools', 'execute_tools'],
            'tools' => $tools,
        ]);
    }
}
