<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Stores;

use DateTimeInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Contracts\VerificationGrantStore;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\GrantSubject;

/**
 * Stores grants in a database table. Single-use consumption is a conditional
 * `UPDATE ... WHERE consumed_at IS NULL`, so the database guarantees that only
 * one concurrent request can consume a given grant.
 */
final class DatabaseVerificationGrantStore implements VerificationGrantStore
{
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly ?string $connection,
        private readonly string $table,
    ) {}

    public function create(string $tokenHash, GrantSubject $subject, string $purpose, string $method, DateTimeInterface $expiresAt): void
    {
        $this->query()->insert([
            'token_hash' => $tokenHash,
            'guard' => $subject->guard,
            'authenticatable_type' => $subject->type,
            'authenticatable_id' => $subject->identifier,
            'purpose' => $purpose,
            'method' => $method,
            'expires_at' => $expiresAt,
            'consumed_at' => null,
            'revoked_at' => null,
            'created_at' => now(),
        ]);
    }

    public function isValid(string $tokenHash, GrantSubject $subject, string $purpose, DateTimeInterface $now): bool
    {
        return $this->usable($tokenHash, $subject, $purpose, $now)->exists();
    }

    public function consume(string $tokenHash, GrantSubject $subject, string $purpose, DateTimeInterface $now): bool
    {
        return $this->usable($tokenHash, $subject, $purpose, $now)->update(['consumed_at' => $now]) === 1;
    }

    public function revokeAll(GrantSubject $subject, ?string $purpose, DateTimeInterface $now, bool $ignoreGuard = false): int
    {
        return $this->query()
            ->unless($ignoreGuard, fn (Builder $query) => $query->where('guard', $subject->guard))
            ->where('authenticatable_type', $subject->type)
            ->where('authenticatable_id', $subject->identifier)
            ->when($purpose !== null, fn (Builder $query) => $query->where('purpose', $purpose))
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now]);
    }

    public function prune(DateTimeInterface $now): int
    {
        return $this->query()
            ->where(fn (Builder $query) => $query
                ->where('expires_at', '<=', $now)
                ->orWhereNotNull('consumed_at')
                ->orWhereNotNull('revoked_at'))
            ->delete();
    }

    private function usable(string $tokenHash, GrantSubject $subject, string $purpose, DateTimeInterface $now): Builder
    {
        return $this->query()
            ->where('token_hash', $tokenHash)
            ->where('guard', $subject->guard)
            ->where('authenticatable_type', $subject->type)
            ->where('authenticatable_id', $subject->identifier)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $now);
    }

    private function query(): Builder
    {
        return $this->connections->connection($this->connection)->table($this->table);
    }
}
