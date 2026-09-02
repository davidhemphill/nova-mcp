<?php

declare(strict_types=1);

namespace NovaAi\McpTools\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Nova\Http\Requests\NotificationRequest;
use Laravel\Nova\Notifications\Notification;
use NovaAi\McpTools\Mcp\Contracts\DescribesNovaSubject;

class UpdateNotificationsTool extends NovaTool implements DescribesNovaSubject
{
    protected string $name = 'nova_notifications_update';

    protected string $title = 'Update Nova Notifications';

    /**
     * @return array{type: string, key: string}
     */
    public function subject(): array
    {
        return ['type' => 'notifications', 'key' => 'notifications'];
    }

    public function description(): string
    {
        return 'Mark the current user\'s Nova notifications as read or unread, or delete them. Target specific notifications by id, or every notification with "all".';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->enum(['read', 'unread', 'delete'])->required()
                ->description('What to do with the targeted notifications.'),
            'ids' => $schema->array()->items($schema->string())
                ->description('Notification ids to act on. Omit and set "all" to target every notification.'),
            'all' => $schema->boolean()->description('Act on every notification belonging to the current user.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'operation' => ['required', 'in:read,unread,delete'],
            'ids' => ['nullable', 'array'],
            'ids.*' => ['required', 'string'],
            'all' => ['nullable', 'boolean'],
        ]);

        $ids = array_values($validated['ids'] ?? []);
        $all = ($validated['all'] ?? false) === true;

        if (! $all && $ids === []) {
            return $this->error('Provide at least one notification id in ids, or set all to true.');
        }

        return $this->guard(fn (): Response => $this->nova->using(
            NotificationRequest::class,
            [],
            [],
            function (NotificationRequest $novaRequest) use ($validated, $ids, $all): Response {
                $query = Notification::query()
                    ->currentUserFromRequest($novaRequest)
                    ->when(! $all, static fn ($builder) => $builder->whereKey($ids));

                $affected = match ($validated['operation']) {
                    'read' => $query->update(['read_at' => now()]),
                    'unread' => $query->update(['read_at' => null]),
                    default => $query->delete(),
                };

                return $this->json([
                    'operation' => $validated['operation'],
                    'affected' => $affected,
                    'unread' => $novaRequest->unreadCount(),
                ]);
            },
            method: 'POST',
        ));
    }
}
