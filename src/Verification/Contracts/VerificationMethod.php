<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use SensitiveParameter;

/**
 * A way of proving that the person operating an authenticated session is the
 * account holder (password, one-time code, authenticator app, ...).
 *
 * Implementations must be stateless with respect to the request, must never
 * log or persist the submitted credentials, and must return `false` (not
 * throw) for credentials that are simply wrong.
 */
interface VerificationMethod
{
    /**
     * @param  array<string, mixed>  $credentials  The raw values submitted by the user.
     */
    public function verify(Authenticatable $user, #[SensitiveParameter] array $credentials, ?string $guard = null): bool;

    /**
     * Whether this method can currently be used by the user (e.g. an
     * authenticator method is unavailable until the user has enrolled).
     */
    public function isAvailableFor(Authenticatable $user, ?string $guard = null): bool;
}
