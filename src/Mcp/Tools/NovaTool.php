<?php

declare(strict_types=1);

namespace Hemp\NovaMcp\Mcp\Tools;

use Closure;
use Hemp\NovaMcp\Nova\NovaContext;
use Hemp\NovaMcp\Nova\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\Paginator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Nova\Filters\FilterEncoder;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

abstract class NovaTool extends Tool
{
    public function __construct(
        protected NovaContext $nova,
        protected Schema $schema,
    ) {
        //
    }

    /**
     * Render a payload as pretty printed JSON.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function json(array $payload): Response
    {
        return Response::text((string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        ));
    }

    /**
     * Translate the exceptions Nova throws into tool errors the agent can act on.
     *
     * @param  Closure(): Response  $callback
     */
    protected function guard(Closure $callback): Response
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            return $this->error('The submitted fields are invalid.', $e->errors());
        } catch (AuthorizationException $e) {
            return $this->error($e->getMessage() ?: 'This action is unauthorized.');
        } catch (ModelNotFoundException) {
            return $this->error('The requested record does not exist.');
        } catch (HttpExceptionInterface $e) {
            return $this->error(match ($e->getStatusCode()) {
                403 => 'This action is unauthorized.',
                404 => 'The requested resource does not exist.',
                409 => 'The record was modified by someone else. Read it again before retrying.',
                default => $e->getMessage() ?: 'Nova rejected the request.',
            });
        } catch (Throwable $e) {
            report($e);

            return $this->error($e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $details
     */
    protected function error(string $message, array $details = []): Response
    {
        return Response::error((string) json_encode(
            array_filter(['error' => $message, 'details' => $details]),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * Encode a `{filterClass: value}` map the way Nova's query string expects it.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function encodeFilters(array $filters): string
    {
        return (new FilterEncoder(collect($filters)
            ->map(static fn (mixed $value, string $key): array => [$key => $value])
            ->values()
            ->all()))->encode();
    }

    /**
     * Clamp a requested page size to the configured bounds.
     */
    protected function perPage(mixed $requested): int
    {
        $default = (int) config('nova-mcp.per_page', 25);
        $max = (int) config('nova-mcp.max_per_page', 100);

        return max(1, min(is_numeric($requested) ? (int) $requested : $default, $max));
    }

    /**
     * Run the callback with Laravel's paginator pinned to the given page.
     *
     * Nova paginates through the framework's global page resolver, which reads
     * the real HTTP request rather than the request we hand to Nova.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function onPage(int $page, Closure $callback): mixed
    {
        Paginator::currentPageResolver(static fn (): int => $page);

        try {
            return $callback();
        } finally {
            Paginator::currentPageResolver(static function (string $pageName = 'page'): int {
                $page = request()->input($pageName);

                return filter_var($page, FILTER_VALIDATE_INT) !== false && (int) $page >= 1 ? (int) $page : 1;
            });
        }
    }
}
