<?php

declare(strict_types=1);

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Syriable\Filament\Plugins\IdentityVerificationAction\Tests\Fixtures\User;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Contracts\VerificationMethod;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Exceptions\InvalidVerification;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Exceptions\VerificationFailure;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\VerificationManager;

beforeEach(function (): void {
    $this->manager = app(VerificationManager::class);
    $this->user = User::createWithPassword('user@example.com', 'correct horse');
});

function verificationFailure(Closure $callback): ?VerificationFailure
{
    try {
        $callback();
    } catch (InvalidVerification $exception) {
        return $exception->reason;
    }

    return null;
}

it('issues a grant for the correct password', function (): void {
    $this->freezeSecond();

    $grant = $this->manager->verify($this->user, 'web', 'password', 'update_email', ['password' => 'correct horse']);

    expect($grant->token)->toHaveLength(64)
        ->and($grant->purpose)->toBe('update_email')
        ->and($grant->method)->toBe('password')
        ->and($grant->subject->identifier)->toBe((string) $this->user->getKey())
        ->and($grant->expiresAt->getTimestamp())->toBe(now()->addSeconds(300)->getTimestamp())
        ->and($this->manager->isValid($grant->token, $this->user, 'web', 'update_email'))->toBeTrue();
});

it('stores only a hash of the token and never the password', function (): void {
    $grant = $this->manager->verify($this->user, 'web', 'password', 'update_email', ['password' => 'correct horse']);

    $row = (array) DB::table('identity_verification_grants')->sole();

    expect($row['token_hash'])->toBe(hash('sha256', $grant->token))
        ->and(json_encode($row))->not->toContain($grant->token)
        ->and(json_encode($row))->not->toContain('correct horse');
});

it('rejects an incorrect password', function (): void {
    expect(verificationFailure(fn () => $this->manager->verify($this->user, 'web', 'password', 'update_email', ['password' => 'wrong'])))
        ->toBe(VerificationFailure::InvalidCredentials)
        ->and(verificationFailure(fn () => $this->manager->verify($this->user, 'web', 'password', 'update_email', [])))
        ->toBe(VerificationFailure::InvalidCredentials)
        ->and(DB::table('identity_verification_grants')->count())->toBe(0);
});

it('rejects unauthenticated verification', function (): void {
    expect(verificationFailure(fn () => $this->manager->verify(null, 'web', 'password', 'update_email', ['password' => 'correct horse'])))
        ->toBe(VerificationFailure::Unauthenticated);
});

it('fails closed for unknown or unavailable methods', function (): void {
    expect(verificationFailure(fn () => $this->manager->verify($this->user, 'web', 'sms', 'update_email', ['code' => '123'])))
        ->toBe(VerificationFailure::MethodUnavailable);

    $passwordless = User::query()->create(['name' => 'No Password', 'email' => 'passwordless@example.com', 'password' => null]);

    expect(verificationFailure(fn () => $this->manager->verify($passwordless, 'web', 'password', 'update_email', ['password' => ''])))
        ->toBe(VerificationFailure::MethodUnavailable);
});

it('rate limits failed attempts per user and resets after a success', function (): void {
    foreach (range(1, 4) as $attempt) {
        verificationFailure(fn () => $this->manager->verify($this->user, 'web', 'password', 'p', ['password' => 'wrong']));
    }

    $this->manager->verify($this->user, 'web', 'password', 'p', ['password' => 'correct horse']);

    foreach (range(1, 5) as $attempt) {
        verificationFailure(fn () => $this->manager->verify($this->user, 'web', 'password', 'p', ['password' => 'wrong']));
    }

    expect(verificationFailure(fn () => $this->manager->verify($this->user, 'web', 'password', 'p', ['password' => 'correct horse'])))
        ->toBe(VerificationFailure::TooManyAttempts);

    $otherUser = User::createWithPassword('other@example.com', 'other password');

    expect($this->manager->verify($otherUser, 'web', 'password', 'p', ['password' => 'other password']))->not->toBeNull();
});

it('binds grants to the user, guard and purpose', function (): void {
    $grant = $this->manager->verify($this->user, 'web', 'password', 'update_email', ['password' => 'correct horse']);
    $otherUser = User::createWithPassword('other@example.com');

    expect($this->manager->isValid($grant->token, $otherUser, 'web', 'update_email'))->toBeFalse()
        ->and($this->manager->isValid($grant->token, $this->user, 'admin', 'update_email'))->toBeFalse()
        ->and($this->manager->isValid($grant->token, $this->user, 'web', 'delete_account'))->toBeFalse()
        ->and($this->manager->consume($grant->token, $otherUser, 'web', 'update_email'))->toBeFalse()
        ->and($this->manager->consume($grant->token, $this->user, 'web', 'delete_account'))->toBeFalse()
        ->and($this->manager->isValid($grant->token, null, 'web', 'update_email'))->toBeFalse()
        ->and($this->manager->isValid($grant->token, $this->user, 'web', 'update_email'))->toBeTrue();
});

it('rejects expired grants', function (): void {
    $grant = $this->manager->verify($this->user, 'web', 'password', 'update_email', ['password' => 'correct horse'], grantLifetime: 30);

    $this->travel(29)->seconds();
    expect($this->manager->isValid($grant->token, $this->user, 'web', 'update_email'))->toBeTrue();

    $this->travel(1)->seconds();
    expect($this->manager->isValid($grant->token, $this->user, 'web', 'update_email'))->toBeFalse()
        ->and($this->manager->consume($grant->token, $this->user, 'web', 'update_email'))->toBeFalse();
});

it('consumes a grant only once', function (): void {
    $grant = $this->manager->verify($this->user, 'web', 'password', 'update_email', ['password' => 'correct horse']);

    expect($this->manager->consume($grant->token, $this->user, 'web', 'update_email'))->toBeTrue()
        ->and($this->manager->consume($grant->token, $this->user, 'web', 'update_email'))->toBeFalse()
        ->and($this->manager->isValid($grant->token, $this->user, 'web', 'update_email'))->toBeFalse();
});

it('revokes grants', function (): void {
    $updateEmail = $this->manager->verify($this->user, 'web', 'password', 'update_email', ['password' => 'correct horse']);
    $deleteAccount = $this->manager->verify($this->user, 'web', 'password', 'delete_account', ['password' => 'correct horse']);
    $otherUser = User::createWithPassword('other@example.com');
    $otherGrant = $this->manager->verify($otherUser, 'web', 'password', 'update_email', ['password' => 'secret-password']);

    expect($this->manager->revokeAll($this->user, 'web', 'update_email'))->toBe(1)
        ->and($this->manager->isValid($updateEmail->token, $this->user, 'web', 'update_email'))->toBeFalse()
        ->and($this->manager->isValid($deleteAccount->token, $this->user, 'web', 'delete_account'))->toBeTrue();

    event(new PasswordReset($this->user));

    expect($this->manager->isValid($deleteAccount->token, $this->user, 'web', 'delete_account'))->toBeFalse()
        ->and($this->manager->isValid($otherGrant->token, $otherUser, 'web', 'update_email'))->toBeTrue();
});

it('rejects malformed tokens without querying for them', function (mixed $token): void {
    DB::enableQueryLog();

    expect($this->manager->isValid($token, $this->user, 'web', 'p'))->toBeFalse()
        ->and($this->manager->consume($token, $this->user, 'web', 'p'))->toBeFalse()
        ->and(DB::getQueryLog())->toBe([]);
})->with([null, true, 1, '', 'short', [['token']]]);

it('rejects invalid purposes', function (string $purpose): void {
    $this->manager->verify($this->user, 'web', 'password', $purpose, ['password' => 'correct horse']);
})->with(['', 'has spaces', str_repeat('a', 101), 'emoji-🔒'])->throws(InvalidArgumentException::class);

it('prunes unusable grants', function (): void {
    $active = $this->manager->verify($this->user, 'web', 'password', 'a', ['password' => 'correct horse']);
    $consumed = $this->manager->verify($this->user, 'web', 'password', 'b', ['password' => 'correct horse']);
    $this->manager->consume($consumed->token, $this->user, 'web', 'b');
    $this->manager->verify($this->user, 'web', 'password', 'c', ['password' => 'correct horse'], grantLifetime: 1);

    $this->travel(2)->seconds();

    $this->artisan('identity-verification:prune')->assertSuccessful();

    expect(DB::table('identity_verification_grants')->pluck('purpose')->all())->toBe(['a'])
        ->and($this->manager->isValid($active->token, $this->user, 'web', 'a'))->toBeTrue();
});

it('registers custom verification methods', function (): void {
    $this->manager->extend('pin', fn (): VerificationMethod => new class implements VerificationMethod
    {
        public function verify(Authenticatable $user, array $credentials, ?string $guard = null): bool
        {
            return ($credentials['pin'] ?? null) === '1234';
        }

        public function isAvailableFor(Authenticatable $user, ?string $guard = null): bool
        {
            return true;
        }
    });

    expect($this->manager->getMethods())->toBe(['password', 'pin'])
        ->and(verificationFailure(fn () => $this->manager->verify($this->user, 'web', 'pin', 'p', ['pin' => '0000'])))->toBe(VerificationFailure::InvalidCredentials)
        ->and($this->manager->verify($this->user, 'web', 'pin', 'p', ['pin' => '1234'])->method)->toBe('pin');
});

it('rejects a method resolver that returns the wrong type', function (): void {
    $this->manager->extend('broken', fn () => new stdClass);

    $this->manager->method('broken');
})->throws(InvalidArgumentException::class);
