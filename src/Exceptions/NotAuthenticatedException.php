<?php

declare(strict_types=1);

namespace NovaAi\McpTools\Exceptions;

use RuntimeException;

/**
 * No Nova user could be resolved for the call.
 *
 * Distinct from a generic failure so the server can answer with a JSON-RPC
 * error rather than letting a revoked token tear the whole process down.
 */
class NotAuthenticatedException extends RuntimeException
{
    //
}
