<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Listeners;

use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\VerificationManager;

/**
 * Revokes outstanding grants when the session ends or the password changes
 * through Laravel's password reset.
 */
final class RevokeVerificationGrants
{
    public function __construct(
        private readonly VerificationManager $manager,
    ) {}

    public function handle(Logout | PasswordReset $event): void
    {
        if (! $event->user) {
            return;
        }

        $this->manager->revokeAll(
            $event->user,
            guard: $event instanceof Logout ? $event->guard : null,
        );
    }
}
