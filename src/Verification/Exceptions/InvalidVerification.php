<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Exceptions;

use RuntimeException;

/**
 * Thrown when an identity verification attempt does not result in a grant.
 * The message is intentionally generic; use `$reason` to decide what to show.
 */
final class InvalidVerification extends RuntimeException
{
    private function __construct(
        public readonly VerificationFailure $reason,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct("Identity verification failed [{$reason->value}].");
    }

    public static function unauthenticated(): self
    {
        return new self(VerificationFailure::Unauthenticated);
    }

    public static function invalidCredentials(): self
    {
        return new self(VerificationFailure::InvalidCredentials);
    }

    public static function tooManyAttempts(int $retryAfterSeconds): self
    {
        return new self(VerificationFailure::TooManyAttempts, $retryAfterSeconds);
    }

    public static function methodUnavailable(): self
    {
        return new self(VerificationFailure::MethodUnavailable);
    }
}
