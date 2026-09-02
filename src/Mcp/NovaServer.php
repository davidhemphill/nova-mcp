<?php

declare(strict_types=1);

namespace Hemp\NovaMcp\Mcp;

use Hemp\NovaMcp\Exceptions\NotAuthenticatedException;
use Hemp\NovaMcp\Mcp\Tools\OverviewTool;
use Hemp\NovaMcp\Mcp\Tools\ResourceSchemaTool;
use Hemp\NovaMcp\Nova\Catalog;
use Hemp\NovaMcp\Nova\NovaContext;
use Hemp\NovaMcp\Nova\Schema;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolSearch;

/**
 * The MCP server backing the Nova "MCP Tools" tool.
 *
 * An overview and a schema reader are always published. Everything Nova
 * registers — resources, lenses, actions, dashboards and notifications — becomes
 * one tool each. Small installations publish those directly; once the catalog
 * grows past the configured threshold it moves behind Laravel MCP's tool search
 * so a client's tool list stays manageable.
 */
class NovaServer extends Server
{
    protected string $name = 'Laravel Nova';

    protected string $version = '1.0.0';

    /**
     * The Nova registration the last handled message saw.
     */
    protected ?string $fingerprint = null;

    /**
     * Rebuild the catalog for every message.
     *
     * Server::boot() runs once when the process starts, which would pin the
     * published tools to whatever Nova had registered at that moment. This hook
     * runs per message, so a resource added while the server is running shows up
     * on the very next call instead of needing a client restart.
     */
    public function createContext(): ServerContext
    {
        $this->boot();

        return parent::createContext();
    }

    /**
     * Tell the client when the catalog changed underneath it.
     */
    public function handle(string $rawMessage): void
    {
        try {
            parent::handle($rawMessage);
        } catch (NotAuthenticatedException $e) {
            // The context is built before the parent's own error handling, so a
            // token revoked mid-session would otherwise end the process instead
            // of answering the call.
            $message = json_decode($rawMessage, true);

            // A message without an id is a notification, and JSON-RPC forbids
            // responding to those — the refusal is only reported to requests.
            if (! is_array($message) || ! isset($message['id'])) {
                return;
            }

            $this->transport->send((string) json_encode([
                'jsonrpc' => '2.0',
                'id' => $message['id'],
                'error' => ['code' => -32001, 'message' => $e->getMessage()],
            ]));

            return;
        }

        $fingerprint = app(NovaContext::class)->registrationFingerprint();

        if ($this->fingerprint !== null && $this->fingerprint !== $fingerprint) {
            $this->transport->send((string) json_encode([
                'jsonrpc' => '2.0',
                'method' => 'notifications/tools/list_changed',
            ]));
        }

        $this->fingerprint = $fingerprint;
    }

    protected function boot(): void
    {
        $this->addCapability('tools.listChanged', true);

        /** @var NovaContext $nova */
        $nova = app(NovaContext::class);
        $nova->boot();

        /** @var Schema $schema */
        $schema = app(Schema::class);

        /** @var Catalog $catalog */
        $catalog = app(Catalog::class);

        $catalogTools = $catalog->tools();

        $this->name = (string) config('nova-mcp.name', $this->name);
        $this->version = (string) config('nova-mcp.version', $this->version);

        $entryPoints = [
            new OverviewTool($nova, $schema, $catalog, $catalogTools),
            new ResourceSchemaTool($nova, $schema, $catalog),
        ];

        $searchable = count($catalogTools) > (int) config('nova-mcp.tool_search_threshold', 40);

        $this->tools = $searchable
            ? [...$entryPoints, ToolSearch::class => $catalogTools]
            : [...$entryPoints, ...$catalogTools];

        $this->instructions = $this->instructions($searchable, $catalogTools);
    }

    /**
     * @param  array<int, Tool>  $catalogTools
     */
    protected function instructions(bool $searchable, array $catalogTools): string
    {
        $discovery = $searchable
            ? <<<'MARKDOWN'
                The remaining tools live in the tool search catalog. Use `search_tools`
                to find one by resource name or operation, then `execute_tools` to run
                it. They are not listed directly and cannot be called by name.
                MARKDOWN
            : <<<'MARKDOWN'
                The remaining tools are listed directly and can be called by name.
                MARKDOWN;

        return <<<MARKDOWN
            This server exposes a Laravel Nova admin panel.

            Start with `nova_overview` to learn which resources, dashboards and tools
            exist and what this server is allowed to do. Before reading or writing a
            resource's records, call `nova_resource_schema` for that resource: it
            returns the exact attributes, validation rules, filter classes and sort
            keys the other tools expect.

            {$discovery}

            Tools are named `nova_<resource>_<operation>`, for example
            `nova_users_list`, `nova_users_get` and `nova_users_update`, plus
            `nova_<resource>_lens_<lens>`, `nova_<resource>_action_<action>` and
            `nova_dashboard_<dashboard>`. This catalog currently holds
            {$this->count($catalogTools)}.

            Every call runs as an authenticated Nova user and is subject to that
            user's Nova policies, so a tool may exist and still refuse an operation.
            MARKDOWN;
    }

    /**
     * @param  array<int, Tool>  $catalogTools
     */
    protected function count(array $catalogTools): string
    {
        $count = count($catalogTools);

        return $count === 1 ? '1 tool' : "{$count} tools";
    }
}
