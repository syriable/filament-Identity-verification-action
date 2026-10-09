<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Verification;

use DateTimeImmutable;

/**
 * A freshly issued grant. The plaintext token exists only in this object; the
 * store keeps a hash of it. Possessing the token is never sufficient on its own:
 * it is only accepted for the subject and purpose it was issued for.
 */
final class VerificationGrant
{
    public function __construct(
        #[\SensitiveParameter]
        public readonly string $token,
        public readonly GrantSubject $subject,
        public readonly string $purpose,
        public readonly string $method,
        public readonly DateTimeImmutable $expiresAt,
    ) {}
}
