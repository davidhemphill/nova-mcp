<?php

declare(strict_types=1);

namespace NovaAi\McpTools\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A single bearer token that authenticates one Nova user with the MCP server.
 *
 * @property string $name
 * @property string $token
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 */
class McpToken extends Model
{
    /**
     * Prefix on the plaintext, so a leaked token is recognizable in logs
     * and by secret scanners.
     */
    public const PREFIX = 'novamcp_';

    protected $table = 'nova_mcp_tokens';

    protected $guarded = [];

    /**
     * The plaintext, populated only on the token that was just minted.
     */
    public ?string $plainTextToken = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function user(): MorphTo
    {
        return $this->morphTo('user');
    }

    /**
     * Mint a token for the given user, returning the model with its plaintext.
     */
    public static function mint(Authenticatable&Model $user, string $name, ?Carbon $expiresAt = null): self
    {
        $plainText = self::PREFIX.Str::random(48);

        $token = new self([
            'name' => $name,
            'token' => self::digest($plainText),
            'expires_at' => $expiresAt,
        ]);

        $token->user()->associate($user);
        $token->save();

        $token->plainTextToken = $plainText;

        return $token;
    }

    /**
     * Find the live token matching the given plaintext.
     */
    public static function findByPlainText(string $plainText): ?self
    {
        if (! str_starts_with($plainText, self::PREFIX)) {
            return null;
        }

        return self::query()->unexpired()->firstWhere('token', self::digest($plainText));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnexpired(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    public function markAsUsed(): void
    {
        // Kept coarse so a busy agent does not write on every single call.
        if ($this->last_used_at?->isAfter(now()->subMinute())) {
            return;
        }

        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    protected static function digest(string $plainText): string
    {
        return hash('sha256', $plainText);
    }
}
