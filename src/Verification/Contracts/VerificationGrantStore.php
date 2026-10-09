<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Contracts;

use DateTimeInterface;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\GrantSubject;

/**
 * Persists verification grants. Tokens are handed to the store already hashed;
 * a store never sees, and must never be able to reconstruct, the plaintext token.
 */
interface VerificationGrantStore
{
    public function create(string $tokenHash, GrantSubject $subject, string $purpose, string $method, DateTimeInterface $expiresAt): void;

    /**
     * Whether an unexpired, unconsumed, unrevoked grant exists for exactly this
     * token, subject and purpose.
     */
    public function isValid(string $tokenHash, GrantSubject $subject, string $purpose, DateTimeInterface $now): bool;

    /**
     * Atomically marks the matching grant as consumed. Returns `true` for exactly
     * one caller, even under concurrent attempts; every other caller gets `false`.
     */
    public function consume(string $tokenHash, GrantSubject $subject, string $purpose, DateTimeInterface $now): bool;

    /**
     * Revokes every outstanding grant of the subject, optionally only for one purpose.
     *
     * @return int The number of grants revoked.
     */
    public function revokeAll(GrantSubject $subject, ?string $purpose, DateTimeInterface $now, bool $ignoreGuard = false): int;

    /**
     * Deletes grants that can no longer be used.
     *
     * @return int The number of grants deleted.
     */
    public function prune(DateTimeInterface $now): int;
}
