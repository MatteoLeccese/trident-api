<?php

declare(strict_types=1);

namespace Src\Realtime\Infrastructure\Broadcasting;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The identity that satisfies Laravel in a product that has no users.
 *
 * It is not an Eloquent model and there is no guard behind it: nothing in the
 * channel authorization chain casts or checks the type. `retrieveUser()` is
 * literally `return $request->user();`, and `Request::user()` is
 * `call_user_func($this->getUserResolver(), $guard)`. Verified in the Laravel
 * 13.31 source, not from memory.
 *
 * It is **per connection, not per person**: the phone changes hands every thirty
 * seconds and the socket does not.
 */
final class GameParticipant implements Authenticatable
{
    public const CONTROLLER = 'controller';

    public const SPECTATOR = 'spectator';

    public function __construct(
        public readonly string $gameId,
        public readonly string $role,
        public readonly string $socketId,
        public readonly bool $canWatch,
    ) {}

    /**
     * Becomes Pusher's presence `user_id`. It has to be a stable, non-null
     * string.
     */
    public function getAuthIdentifier(): string
    {
        return "{$this->role}:{$this->socketId}";
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void
    {
        // There is no session to remember.
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
