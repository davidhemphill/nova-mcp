<?php

use Hemp\NovaMcp\Http\Middleware\AuthenticateMcpToken;
use Hemp\NovaMcp\Http\Middleware\Authorize;
use Laravel\Nova\Http\Middleware\Authorize as NovaAuthorize;

return [

    /*
    |--------------------------------------------------------------------------
    | Server Identity
    |--------------------------------------------------------------------------
    |
    | The name and version reported to MCP clients during initialization.
    |
    */

    'name' => env('NOVA_MCP_NAME', 'Laravel Nova'),

    'version' => '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | Token
    |--------------------------------------------------------------------------
    |
    | The token the stdio transport authenticates with. Mint one from the
    | Nova MCP screen in Nova. Prefer setting it in the MCP client's own
    | configuration over this application's .env, so each agent carries
    | its own revocable identity.
    |
    */

    'token' => env('NOVA_MCP_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Transports
    |--------------------------------------------------------------------------
    |
    | Both transports authenticate with the same Nova-minted token: HTTP reads
    | it from the Authorization header, stdio from the configuration above.
    | Nova's own gate then decides whether that user may reach the panel.
    |
    */

    'web' => [
        'enabled' => env('NOVA_MCP_WEB', true),
        'route' => env('NOVA_MCP_ROUTE', 'nova-vendor/nova-mcp/mcp'),

        'middleware' => [
            'nova:serving',
            AuthenticateMcpToken::class,
            NovaAuthorize::class,
            Authorize::class,
        ],
    ],

    'local' => [
        'enabled' => env('NOVA_MCP_LOCAL', true),
        'handle' => env('NOVA_MCP_LOCAL_HANDLE', 'nova'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Capabilities
    |--------------------------------------------------------------------------
    |
    | Nova policies always apply on top of these switches; they only decide
    | which tools are published in the catalog at all. Disabling "writes"
    | leaves a read-only server that can still browse every resource.
    |
    */

    'writes' => env('NOVA_MCP_WRITES', true),

    'actions' => env('NOVA_MCP_ACTIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    'per_page' => (int) env('NOVA_MCP_PER_PAGE', 25),

    'max_per_page' => (int) env('NOVA_MCP_MAX_PER_PAGE', 100),

    /*
    |--------------------------------------------------------------------------
    | Tool Search Threshold
    |--------------------------------------------------------------------------
    |
    | Catalogs at or below this size are published directly, so clients can call
    | every tool by name. Larger catalogs move behind Laravel MCP's tool search,
    | which publishes "search_tools" and "execute_tools" instead.
    |
    */

    'tool_search_threshold' => (int) env('NOVA_MCP_TOOL_SEARCH_THRESHOLD', 40),

    /*
    |--------------------------------------------------------------------------
    | Resource Filtering
    |--------------------------------------------------------------------------
    |
    | URI keys of the Nova resources to publish. An empty "only" array
    | publishes every resource the acting user is authorized to see.
    |
    */

    'resources' => [
        'only' => [],
        'except' => [],
    ],

];
