<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Methods;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Hashing\Hasher;
use SensitiveParameter;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Contracts\VerificationMethod;

/**
 * Re-confirms the current password of the authenticated user.
 *
 * The check is delegated to the guard's user provider, so it honours whatever
 * provider and hashing the application configured. If the guard does not expose
 * a provider, the configured hasher is used against `getAuthPassword()`.
 */
final class PasswordVerification implements VerificationMethod
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Hasher $hasher,
    ) {}

    public function verify(Authenticatable $user, #[SensitiveParameter] array $credentials, ?string $guard = null): bool
    {
        $password = $credentials['password'] ?? null;

        if (! is_string($password) || $password === '') {
            return false;
        }

        $provider = $this->resolveProvider($guard);

        if ($provider) {
            return $provider->validateCredentials($user, ['password' => $password]);
        }

        $hashedPassword = $user->getAuthPassword();

        if (! is_string($hashedPassword) || $hashedPassword === '') {
            return false;
        }

        return $this->hasher->check($password, $hashedPassword);
    }

    public function isAvailableFor(Authenticatable $user, ?string $guard = null): bool
    {
        $hashedPassword = $user->getAuthPassword();

        return is_string($hashedPassword) && $hashedPassword !== '';
    }

    private function resolveProvider(?string $guard): ?UserProvider
    {
        $guardInstance = $this->auth->guard($guard);

        if (! method_exists($guardInstance, 'getProvider')) {
            return null;
        }

        $provider = $guardInstance->getProvider();

        return $provider instanceof UserProvider ? $provider : null;
    }
}
