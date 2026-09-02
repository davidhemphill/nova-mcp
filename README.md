# Nova MCP

Expose a [Laravel Nova](https://nova.laravel.com) admin panel to AI agents over the
[Model Context Protocol](https://modelcontextprotocol.io). Every resource, lens,
action, dashboard and the notification center becomes an MCP tool, and every call
runs as an authenticated Nova user through your existing policies.

## Requirements

- PHP 8.3+
- Laravel 11, 12 or 13
- Laravel Nova 5
- [laravel/mcp](https://github.com/laravel/mcp)

## Installation

```bash
composer require hemp/nova-mcp
php artisan migrate
```

Register the tool in your `NovaServiceProvider`:

```php
use Hemp\NovaMcp\NovaMcp;

public function tools(): array
{
    return [
        new NovaMcp,
    ];
}
```

That is the whole install. A **Nova MCP** screen appears in Nova's sidebar,
showing the published catalog and managing access tokens.

## Connecting a client

Authentication is a token minted from the Nova MCP screen in Nova. Tokens act
as the user who minted them, are stored only as SHA-256 digests, may carry an
expiry, and stop working the moment they are revoked — even mid-session.

**HTTP** — point the client at `https://your-app.test/nova-vendor/nova-mcp/mcp`
with an `Authorization: Bearer <token>` header.

**stdio** — for local agents such as Claude Desktop:

```json
{
    "mcpServers": {
        "nova": {
            "command": "php",
            "args": ["/path/to/your-app/artisan", "mcp:start", "nova"],
            "env": { "NOVA_MCP_TOKEN": "novamcp_..." }
        }
    }
}
```

## What gets published

Two orientation tools are always listed directly:

| Tool | Purpose |
| --- | --- |
| `nova_overview` | Resources, dashboards, capabilities and the acting user |
| `nova_resource_schema` | Fields, validation rules, filters, lenses and actions for one resource |

Everything else is generated from what Nova has registered, named
`nova_<resource>_<operation>` — `nova_posts_list`, `nova_posts_get`,
`nova_posts_create`, `nova_posts_action_publish`,
`nova_posts_lens_most_viewed`, `nova_dashboard_main`, and so on. Catalogs
larger than the configured threshold are published behind Laravel MCP's
`search_tools` / `execute_tools` pair so a client's tool list stays manageable.

The catalog is rebuilt from Nova's live registration on every call, so a
resource added while a server is running appears on the next request, and the
server announces the change via `tools/list_changed`.

## Configuration

```bash
php artisan vendor:publish --tag=nova-mcp-config
```

Notable options in `config/nova-mcp.php`:

| Key | Default | Purpose |
| --- | --- | --- |
| `writes` | `true` | Publish create / update / delete tools |
| `actions` | `true` | Publish Nova action tools |
| `web.enabled` / `local.enabled` | `true` | Toggle each transport |
| `tool_search_threshold` | `40` | Catalog size before tool search engages |
| `resources.only` / `resources.except` | `[]` | Limit which resources are published |

## Security model

- Both transports require a minted token; there is no unauthenticated path.
  An empty middleware stack refuses to register the endpoint rather than
  serving it open.
- Every call is subject to Nova's own authorization — the `viewNova` gate and
  your resource policies — so a tool can exist and still refuse.
- Deletion tools report exactly which records were affected and which were
  skipped, rather than assuming the request succeeded.

## Testing

```bash
composer test
```

## License

[MIT](LICENSE)
