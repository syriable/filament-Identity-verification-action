<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Verification;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SensitiveParameter;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Contracts\VerificationGrantStore;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Contracts\VerificationMethod;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Exceptions\InvalidVerification;

/**
 * Coordinates identity verification: resolves the requested method, applies
 * rate limiting, and issues, validates, consumes and revokes grants.
 *
 * This class is UI agnostic. It never logs or stores submitted credentials.
 */
class VerificationManager
{
    /**
     * Method instances are resolved on every use rather than cached, so that a
     * long-lived manager (e.g. under Octane) never holds request-scoped state.
     *
     * @var array<string, Closure(): VerificationMethod>
     */
    protected array $methodResolvers = [];

    /**
     * @param  int  $grantLifetime  Seconds a grant stays valid by default.
     * @param  int  $maxAttempts  Failed attempts allowed per subject within `$decaySeconds`.
     */
    public function __construct(
        protected VerificationGrantStore $grants,
        protected RateLimiter $rateLimiter,
        protected int $grantLifetime = 300,
        protected int $maxAttempts = 5,
        protected int $decaySeconds = 60,
    ) {}

    /**
     * Registers (or replaces) a verification method under a key.
     *
     * @param  Closure(): VerificationMethod  $resolver
     */
    public function extend(string $method, Closure $resolver): static
    {
        $this->methodResolvers[$method] = $resolver;

        return $this;
    }

    public function hasMethod(string $method): bool
    {
        return array_key_exists($method, $this->methodResolvers);
    }

    /**
     * @return array<string>
     */
    public function getMethods(): array
    {
        return array_keys($this->methodResolvers);
    }

    public function method(string $method): VerificationMethod
    {
        $resolver = $this->methodResolvers[$method] ?? throw new InvalidArgumentException("Identity verification method [{$method}] is not registered.");

        $instance = $resolver();

        if (! $instance instanceof VerificationMethod) {
            throw new InvalidArgumentException("Identity verification method [{$method}] must resolve to an instance of [" . VerificationMethod::class . '].');
        }

        return $instance;
    }

    /**
     * Verifies the user with the given method and, on success, issues a grant
     * bound to the user, guard and purpose.
     *
     * @param  array<string, mixed>  $credentials
     *
     * @throws InvalidVerification
     */
    public function verify(
        ?Authenticatable $user,
        string $guard,
        string $method,
        string $purpose,
        #[SensitiveParameter]
        array $credentials,
        ?int $grantLifetime = null,
    ): VerificationGrant {
        $this->ensureValidPurpose($purpose);

        if (! $user) {
            throw InvalidVerification::unauthenticated();
        }

        $subject = GrantSubject::fromUser($user, $guard);
        $rateLimitKey = $this->rateLimitKey($subject);

        if ($this->rateLimiter->tooManyAttempts($rateLimitKey, $this->maxAttempts)) {
            throw InvalidVerification::tooManyAttempts($this->rateLimiter->availableIn($rateLimitKey));
        }

        if (! $this->hasMethod($method)) {
            throw InvalidVerification::methodUnavailable();
        }

        $verificationMethod = $this->method($method);

        if (! $verificationMethod->isAvailableFor($user, $guard)) {
            throw InvalidVerification::methodUnavailable();
        }

        if (! $verificationMethod->verify($user, $credentials, $guard)) {
            $this->rateLimiter->hit($rateLimitKey, $this->decaySeconds);

            throw InvalidVerification::invalidCredentials();
        }

        $this->rateLimiter->clear($rateLimitKey);

        return $this->issue($subject, $purpose, $method, $grantLifetime);
    }

    /**
     * Issues a grant without performing a verification. Only call this after the
     * user has been verified by other trusted means.
     */
    public function issue(GrantSubject $subject, string $purpose, string $method, ?int $lifetime = null): VerificationGrant
    {
        $this->ensureValidPurpose($purpose);

        $lifetime ??= $this->grantLifetime;

        if ($lifetime < 1) {
            throw new InvalidArgumentException('The grant lifetime must be at least one second.');
        }

        $token = Str::random(64);
        $expiresAt = $this->now()->add(new DateInterval("PT{$lifetime}S"));

        $this->grants->create($this->hashToken($token), $subject, $purpose, $method, $expiresAt);

        return new VerificationGrant($token, $subject, $purpose, $method, $expiresAt);
    }

    /**
     * Whether the token is a usable grant for this user, guard and purpose.
     * Does not consume the grant.
     */
    public function isValid(#[SensitiveParameter] mixed $token, ?Authenticatable $user, string $guard, string $purpose): bool
    {
        if (! $this->isWellFormedToken($token) || ! $user) {
            return false;
        }

        return $this->grants->isValid($this->hashToken($token), GrantSubject::fromUser($user, $guard), $purpose, $this->now());
    }

    /**
     * Atomically consumes the grant. Returns `true` only if the token was a usable
     * grant for this user, guard and purpose and no other request consumed it first.
     */
    public function consume(#[SensitiveParameter] mixed $token, ?Authenticatable $user, string $guard, string $purpose): bool
    {
        if (! $this->isWellFormedToken($token) || ! $user) {
            return false;
        }

        return $this->grants->consume($this->hashToken($token), GrantSubject::fromUser($user, $guard), $purpose, $this->now());
    }

    /**
     * Revokes the user's outstanding grants. When `$guard` is null, grants issued
     * on every guard are revoked.
     */
    public function revokeAll(Authenticatable $user, ?string $guard = null, ?string $purpose = null): int
    {
        return $this->grants->revokeAll(
            GrantSubject::fromUser($user, $guard ?? ''),
            $purpose,
            $this->now(),
            ignoreGuard: $guard === null,
        );
    }

    public function prune(): int
    {
        return $this->grants->prune($this->now());
    }

    public function getGrantLifetime(): int
    {
        return $this->grantLifetime;
    }

    protected function hashToken(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @phpstan-assert-if-true string $token
     */
    protected function isWellFormedToken(#[SensitiveParameter] mixed $token): bool
    {
        return is_string($token) && strlen($token) === 64;
    }

    protected function ensureValidPurpose(string $purpose): void
    {
        if (! preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $purpose)) {
            throw new InvalidArgumentException("The identity verification purpose [{$purpose}] is invalid. Use 1-100 letters, digits, or the characters _ . : -");
        }
    }

    protected function rateLimitKey(GrantSubject $subject): string
    {
        return 'identity-verification:' . $subject->key();
    }

    protected function now(): DateTimeImmutable
    {
        return now()->toDateTimeImmutable();
    }
}
