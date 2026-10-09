<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Verification;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The identity a grant is bound to: the authenticatable's class and key, plus
 * the guard it was authenticated with. Two users of different models sharing a
 * primary key, or the same user on another guard, are different subjects.
 */
final class GrantSubject
{
    public function __construct(
        public readonly string $guard,
        public readonly string $type,
        public readonly string $identifier,
    ) {}

    public static function fromUser(Authenticatable $user, string $guard): self
    {
        return new self($guard, $user::class, (string) $user->getAuthIdentifier());
    }

    public function key(): string
    {
        return hash('sha256', "{$this->guard}|{$this->type}|{$this->identifier}");
    }
}
