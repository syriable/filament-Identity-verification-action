<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Syriable\Filament\Plugins\IdentityVerificationAction\Actions\IdentityVerificationAction;
use Syriable\Filament\Plugins\IdentityVerificationAction\Actions\IdentityVerificationContext;
use Syriable\Filament\Plugins\IdentityVerificationAction\Actions\ProtectedAction;
use Syriable\Filament\Plugins\IdentityVerificationAction\IdentityVerificationActionPlugin;
use Syriable\Filament\Plugins\IdentityVerificationAction\IdentityVerificationActionServiceProvider;
use Syriable\Filament\Plugins\IdentityVerificationAction\Tests\Fixtures\AccountSettings;
use Syriable\Filament\Plugins\IdentityVerificationAction\Tests\Fixtures\User;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Contracts\VerificationGrantStore;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Stores\DatabaseVerificationGrantStore;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\VerificationManager;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

it('autoloads the package namespace', function (): void {
    expect(class_exists(IdentityVerificationActionServiceProvider::class))->toBeTrue()
        ->and(class_exists(IdentityVerificationActionPlugin::class))->toBeTrue()
        ->and(class_exists(ProtectedAction::class))->toBeTrue()
        ->and(class_exists(VerificationManager::class))->toBeTrue();
});

it('declares the service provider for package discovery', function (): void {
    $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);

    expect($composer['name'])->toBe('syriable/filament-identity-verification-action')
        ->and($composer['autoload']['psr-4'])->toHaveKey('Syriable\\Filament\\Plugins\\IdentityVerificationAction\\')
        ->and($composer['extra']['laravel']['providers'])->toBe([IdentityVerificationActionServiceProvider::class]);
});

it('boots the service provider and binds the core services', function (): void {
    expect(app()->getProviders(IdentityVerificationActionServiceProvider::class))->not->toBeEmpty()
        ->and(app(VerificationManager::class))->toBe(app(VerificationManager::class))
        ->and(app(VerificationGrantStore::class))->toBeInstanceOf(DatabaseVerificationGrantStore::class)
        ->and(app(VerificationManager::class)->getMethods())->toBe(['password'])
        ->and(config('filament-identity-verification-action.grants.lifetime'))->toBe(300);
});

it('loads translations', function (): void {
    expect(__('filament-identity-verification-action::messages.action.modal.heading'))->toBe('Confirm your identity')
        ->and(__('filament-identity-verification-action::messages.errors.invalid_credentials.password'))->toBe('The password is incorrect.');
});

it('provides the grants migration', function (): void {
    expect(Schema::hasTable('identity_verification_grants'))->toBeTrue()
        ->and(Schema::hasColumns('identity_verification_grants', [
            'token_hash', 'guard', 'authenticatable_type', 'authenticatable_id',
            'purpose', 'method', 'expires_at', 'consumed_at', 'revoked_at',
        ]))->toBeTrue();

    $this->artisan('vendor:publish', ['--tag' => 'filament-identity-verification-action-migrations', '--force' => true])
        ->assertSuccessful();

    $published = glob(database_path('migrations/*_create_identity_verification_grants_table.php'));

    expect($published)->toHaveCount(1);

    array_map('unlink', $published);
});

it('registers the plugin on a panel', function (): void {
    $panel = Filament::getPanel('admin');

    expect($panel->hasPlugin(IdentityVerificationActionPlugin::ID))->toBeTrue()
        ->and($panel->getPlugin(IdentityVerificationActionPlugin::ID))->toBeInstanceOf(IdentityVerificationActionPlugin::class)
        ->and(IdentityVerificationActionPlugin::make()->getId())->toBe('filament-identity-verification-action');
});

it('creates independent plugin instances per panel', function (): void {
    $first = IdentityVerificationActionPlugin::make()->grantLifetime(60);
    $second = IdentityVerificationActionPlugin::make();

    expect($first)->not->toBe($second)
        ->and($second->getGrantLifetime())->toBeNull();
});

it('applies per-panel plugin settings to issued grants', function (): void {
    Filament::setCurrentPanel('admin');
    Filament::getPanel('admin')->getPlugin(IdentityVerificationActionPlugin::ID)->grantLifetime(42);

    actingAs(User::createWithPassword('user@example.com'));

    livewire(AccountSettings::class)
        ->mountAction('updateEmail')
        ->fillForm(['password' => 'secret-password'])
        ->callMountedAction();

    $expiresAt = DB::table('identity_verification_grants')->value('expires_at');

    expect(now()->diffInSeconds($expiresAt))->toEqualWithDelta(42, 1);
});

it('uses the application default guard outside of a panel', function (): void {
    Filament::setCurrentPanel(null);

    config()->set('auth.defaults.guard', 'web');

    expect(IdentityVerificationContext::guard())->toBe('web')
        ->and(IdentityVerificationContext::plugin())->toBeNull()
        ->and(IdentityVerificationContext::defaultMethod())->toBe('password');
});

it('names the verification action consistently', function (): void {
    expect(IdentityVerificationAction::getDefaultName())->toBe('identityVerification')
        ->and(IdentityVerificationAction::make()->getName())->toBe('identityVerification');
});

it('refuses a non-positive grant lifetime', function (): void {
    IdentityVerificationActionPlugin::make()->grantLifetime(0);
})->throws(InvalidArgumentException::class);
