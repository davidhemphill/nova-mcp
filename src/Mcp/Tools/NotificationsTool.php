<?php

declare(strict_types=1);

namespace Hemp\NovaMcp\Mcp\Tools;

use Hemp\NovaMcp\Mcp\Contracts\DescribesNovaSubject;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Nova\Http\Requests\NotificationRequest;
use Laravel\Nova\Notifications\Notification;

#[IsReadOnly]
#[IsIdempotent]
class NotificationsTool extends NovaTool implements DescribesNovaSubject
{
    protected string $name = 'nova_notifications';

    protected string $title = 'Nova Notifications';

    /**
     * @return array{type: string, key: string}
     */
    public function subject(): array
    {
        return ['type' => 'notifications', 'key' => 'notifications'];
    }

    public function description(): string
    {
        return 'Read the Nova notification centre for the current user: the latest notifications with their message, type, action link and read state, plus the unread count.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'unreadOnly' => $schema->boolean()->description('Return only unread notifications.'),
            'limit' => $schema->integer()->min(1)->max(100)->description('Maximum notifications to return. Defaults to 25.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'unreadOnly' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return $this->guard(fn (): Response => $this->nova->using(
            NotificationRequest::class,
            [],
            [],
            function (NotificationRequest $novaRequest) use ($validated): Response {
                $notifications = Notification::query()
                    ->currentUserFromRequest($novaRequest)
                    ->when($validated['unreadOnly'] ?? false, static fn ($query) => $query->whereNull('read_at'))
                    ->latest()
                    ->take((int) ($validated['limit'] ?? 25))
                    ->get();

                return $this->json([
                    'unread' => $novaRequest->unreadCount(),
                    'notifications' => $notifications->map(static fn (Notification $notification): array => [
                        'id' => $notification->getKey(),
                        'type' => $notification->type,
                        'readAt' => $notification->read_at?->toAtomString(),
                        'createdAt' => $notification->created_at?->toAtomString(),
                        'data' => $notification->data,
                    ])->all(),
                ]);
            },
        ));
    }
}
