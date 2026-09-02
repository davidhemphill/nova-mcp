<?php

declare(strict_types=1);

namespace NovaAi\McpTools\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Nova\Card;
use Laravel\Nova\Http\Requests\DashboardMetricRequest;
use Laravel\Nova\Metrics\Metric;
use Laravel\Nova\Nova;
use NovaAi\McpTools\Mcp\Contracts\DescribesNovaSubject;
use NovaAi\McpTools\Nova\NovaContext;
use NovaAi\McpTools\Nova\Schema;
use Throwable;

#[IsReadOnly]
#[IsIdempotent]
class DashboardTool extends NovaTool implements DescribesNovaSubject
{
    public function __construct(
        NovaContext $nova,
        Schema $schema,
        protected string $uriKey,
        protected string $dashboardName,
    ) {
        parent::__construct($nova, $schema);

        $this->name = sprintf('nova_dashboard_%s', ResourceTool::normalize($this->uriKey));
    }

    /**
     * @return array{type: string, key: string}
     */
    public function subject(): array
    {
        return ['type' => 'dashboard', 'key' => $this->uriKey];
    }

    public function title(): string
    {
        return sprintf('Dashboard: %s', $this->dashboardName);
    }

    public function description(): string
    {
        return sprintf(
            'Read the Nova "%s" dashboard: every card the current user is authorized to see, with each metric resolved to its current value.',
            $this->dashboardName,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'range' => $schema->string()
                ->description('Range key to resolve value and trend metrics over, for example "30" or "MTD". Metric defaults are used when omitted.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'range' => ['nullable', 'string', 'max:32'],
        ]);

        $parameters = array_filter([
            'dashboard' => $this->uriKey,
            'range' => $validated['range'] ?? null,
            'timezone' => config('app.timezone'),
        ], static fn (mixed $value): bool => $value !== null);

        return $this->guard(fn (): Response => $this->nova->using(
            DashboardMetricRequest::class,
            ['dashboard' => $this->uriKey],
            $parameters,
            function (DashboardMetricRequest $novaRequest): Response {
                $cards = Nova::availableDashboardCardsForDashboard($this->uriKey, $novaRequest);

                return $this->json([
                    'dashboard' => $this->uriKey,
                    'name' => $this->dashboardName,
                    'cards' => $cards
                        ->map(fn (Card $card): array => $this->card($card, $novaRequest))
                        ->values()
                        ->all(),
                ]);
            },
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function card(Card $card, DashboardMetricRequest $novaRequest): array
    {
        $payload = array_filter([
            'uriKey' => method_exists($card, 'uriKey') ? $card->uriKey() : null,
            'name' => method_exists($card, 'name') ? (string) $card->name() : null,
            'component' => $card->component(),
        ], static fn (mixed $value): bool => $value !== null);

        if (! $card instanceof Metric) {
            return $payload + ['type' => 'card'];
        }

        try {
            $value = $card->resolve($novaRequest);

            return $payload + [
                'type' => 'metric',
                'value' => json_decode((string) json_encode($value), true),
            ];
        } catch (Throwable $e) {
            report($e);

            return $payload + ['type' => 'metric', 'error' => $e->getMessage()];
        }
    }
}
