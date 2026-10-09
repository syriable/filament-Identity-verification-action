<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction;

use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Syriable\Filament\Plugins\IdentityVerificationAction\Commands\PruneVerificationGrantsCommand;
use Syriable\Filament\Plugins\IdentityVerificationAction\Listeners\RevokeVerificationGrants;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Contracts\VerificationGrantStore;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Contracts\VerificationMethod;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Stores\DatabaseVerificationGrantStore;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\VerificationManager;

class IdentityVerificationActionServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-identity-verification-action';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasTranslations()
            ->hasMigration('create_identity_verification_grants_table')
            ->hasCommand(PruneVerificationGrantsCommand::class)
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations();
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(VerificationGrantStore::class, function (Application $app): VerificationGrantStore {
            $connection = config('filament-identity-verification-action.grants.connection');
            $table = config('filament-identity-verification-action.grants.table', 'identity_verification_grants');

            return new DatabaseVerificationGrantStore(
                $app->make(ConnectionResolverInterface::class),
                is_string($connection) ? $connection : null,
                is_string($table) ? $table : 'identity_verification_grants',
            );
        });

        $this->app->singleton(VerificationManager::class, function (Application $app): VerificationManager {
            $manager = new VerificationManager(
                $app->make(VerificationGrantStore::class),
                $app->make(RateLimiter::class),
                grantLifetime: $this->integerConfig('grants.lifetime', 300),
                maxAttempts: $this->integerConfig('rate_limiting.max_attempts', 5),
                decaySeconds: $this->integerConfig('rate_limiting.decay_seconds', 60),
            );

            /** @var array<string, class-string<VerificationMethod>> $methods */
            $methods = config('filament-identity-verification-action.methods', []);

            foreach ($methods as $key => $class) {
                $manager->extend($key, fn (): VerificationMethod => app($class));
            }

            return $manager;
        });
    }

    public function packageBooted(): void
    {
        Event::listen([Logout::class, PasswordReset::class], RevokeVerificationGrants::class);
    }

    protected function integerConfig(string $key, int $default): int
    {
        $value = config("filament-identity-verification-action.{$key}", $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
