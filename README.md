# Nova MCP

Nova MCP gives AI agents a way into your [Laravel Nova](https://nova.laravel.com)
panel over the [Model Context Protocol](https://modelcontextprotocol.io). Your
resources, lenses, actions, dashboards, and notifications all become MCP tools.

Every request runs as the Nova user who created the token, so your policies still
do their job.

## Requirements

- PHP 8.3+
- Laravel 11, 12, or 13
- Nova 5
- [laravel/mcp](https://github.com/laravel/mcp)

## Install

```bash
composer require hemp/nova-mcp
php artisan migrate
```

Then register the tool in your `NovaServiceProvider`:

```php
use Hemp\NovaMcp\NovaMcp;

public function tools(): array
{
    return [
        new NovaMcp,
    ];
}
```

You'll get a **Nova MCP** item in the Nova sidebar, where you can see the
published tools and manage tokens.

## Make a token

Head to the Nova MCP screen and create one. It acts as the user who created it,
you can give it an expiry, and revoking it cuts off access right away, even
mid-session. Only a SHA-256 digest of the token gets stored.

## Connect a client

The package serves both transports. Over HTTP, point your client at:

```text
https://your-app.test/nova-vendor/nova-mcp/mcp
```

with the token in the authorization header:

```http
Authorization: Bearer <token>
```

Locally, run the server over stdio with `php artisan mcp:start nova` and pass the
token as `NOVA_MCP_TOKEN`.

### Claude Code

Over HTTP:

```bash
claude mcp add --transport http nova https://your-app.test/nova-vendor/nova-mcp/mcp \
  --header "Authorization: Bearer novamcp_..."
```

Or over stdio, straight against your local app:

```bash
claude mcp add --env NOVA_MCP_TOKEN=novamcp_... --transport stdio nova \
  -- php /path/to/your-app/artisan mcp:start nova
```

### Codex

```bash
codex mcp add nova --env NOVA_MCP_TOKEN=novamcp_... \
  -- php /path/to/your-app/artisan mcp:start nova
```

For the HTTP transport, add it to `~/.codex/config.toml` yourself. The bearer
token is read from an environment variable, not written in the file:

```toml
[mcp_servers.nova]
url = "https://your-app.test/nova-vendor/nova-mcp/mcp"
bearer_token_env_var = "NOVA_MCP_TOKEN"
```

### Claude Desktop

Drop this in your config and fix up the path:

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

## What the client sees

Two tools are always there:

| Tool | What it does |
| --- | --- |
| `nova_overview` | Lists resources, dashboards, capabilities, and the current user |
| `nova_resource_schema` | Fields, validation rules, filters, lenses, and actions for one resource |

The rest come from your registered resources, named
`nova_<resource>_<operation>`. So you'll see things like `nova_posts_list`,
`nova_posts_get`, `nova_posts_create`, `nova_posts_action_publish`,
`nova_posts_lens_most_viewed`, and `nova_dashboard_main`.

Once the catalog gets big enough (40 tools by default), Laravel MCP puts it
behind `search_tools` and `execute_tools` so the client isn't drowning in a tool
list.

The catalog is rebuilt from Nova's live registration on every request. Add a
resource while the server is running and it shows up on the next request, along
with a `tools/list_changed` notification.

## Configuration

```bash
php artisan vendor:publish --tag=nova-mcp-config
```

The interesting bits of `config/nova-mcp.php`:

| Key | Default | What it does |
| --- | --- | --- |
| `writes` | `true` | Publish create, update, and delete tools |
| `actions` | `true` | Publish Nova action tools |
| `web.enabled` / `local.enabled` | `true` | Turn each transport on or off |
| `tool_search_threshold` | `40` | Catalog size that switches on tool search |
| `resources.only` / `resources.except` | `[]` | Limit which resources get published |

## Security

Both transports need a token. If the middleware stack ends up empty, Nova MCP
refuses to register the endpoint rather than leave it open.

Requests go through Nova's `viewNova` gate and your resource policies, so a tool
can show up in the catalog and still turn down the request if the user isn't
allowed. Delete tools tell you what they changed and what they skipped.

## Testing

```bash
composer test
```

## License

[MIT](LICENSE)
