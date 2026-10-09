<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Syriable\Filament\Plugins\IdentityVerificationAction\Actions\Contracts\ProtectedByIdentityVerification;
use Syriable\Filament\Plugins\IdentityVerificationAction\Tests\Fixtures\AccountSettings;
use Syriable\Filament\Plugins\IdentityVerificationAction\Tests\Fixtures\ActionLog;
use Syriable\Filament\Plugins\IdentityVerificationAction\Tests\Fixtures\User;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    ActionLog::reset();
    AccountSettings::$canChangeSecuritySettings = true;

    Filament::setCurrentPanel('admin');

    $this->user = User::createWithPassword('old@example.com');

    actingAs($this->user);
});

it('opens the verification modal instead of the protected form', function (): void {
    $component = livewire(AccountSettings::class)
        ->mountAction('updateEmail')
        ->assertActionMounted(['updateEmail', 'identityVerification'])
        ->assertMountedActionModalSee(['Confirm your identity', 'Current password']);

    expect($component->get('mountedActions.0.data'))->not->toHaveKey('email')
        ->and(ActionLog::has('updateEmail.fill'))->toBeFalse();
});

it('replaces the verification action with the protected action after a correct password', function (): void {
    $component = livewire(AccountSettings::class)
        ->mountAction('updateEmail')
        ->fillForm(['password' => 'secret-password'])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertActionMounted('updateEmail')
        ->assertActionNotMounted(['updateEmail', 'identityVerification'])
        ->assertSchemaStateSet(['email' => 'old@example.com']);

    expect($component->get('mountedActions'))->toHaveCount(1)
        ->and($component->get('mountedActions.0.arguments.' . ProtectedByIdentityVerification::GRANT_ARGUMENT))->toBeString()->toHaveLength(64);

    $component
        ->fillForm(['email' => 'new@example.com'])
        ->callMountedAction()
        ->assertHasNoErrors()
        ->assertActionNotMounted('updateEmail');

    expect($this->user->fresh()->email)->toBe('new@example.com')
        ->and(ActionLog::$entries)->toBe(['updateEmail.fill', 'updateEmail.before', 'updateEmail']);
});

it('keeps the verification modal open and shows an error for an incorrect password', function (): void {
    $component = livewire(AccountSettings::class)
        ->mountAction('updateEmail')
        ->fillForm(['password' => 'wrong-password'])
        ->callMountedAction()
        ->assertHasFormErrors(['password'])
        ->assertActionMounted(['updateEmail', 'identityVerification']);

    expect($component->get('mountedActions.1.data.password'))->toBeNull()
        ->and($component->errors()->first('mountedActions.1.data.password'))->toBe('The password is incorrect.')
        ->and(ActionLog::$entries)->toBe([])
        ->and(DB::table('identity_verification_grants')->count())->toBe(0);
});

it('requires a password', function (): void {
    livewire(AccountSettings::class)
        ->mountAction('updateEmail')
        ->callMountedAction()
        ->assertHasFormErrors(['password' => 'required'])
        ->assertActionMounted(['updateEmail', 'identityVerification']);
});

it('never executes the protected action when verification is cancelled', function (): void {
    livewire(AccountSettings::class)
        ->mountAction('updateEmail')
        ->unmountAction()
        ->assertActionNotMounted('updateEmail')
        ->assertActionNotMounted(['updateEmail', 'identityVerification'])
        ->assertSet('mountedActions', []);

    expect(ActionLog::$entries)->toBe([])
        ->and(DB::table('identity_verification_grants')->count())->toBe(0);
});

it('never executes the protected action when its modal is cancelled after verification', function (): void {
    livewire(AccountSettings::class)
        ->mountAction('updateEmail')
        ->fillForm(['password' => 'secret-password'])
        ->callMountedAction()
        ->assertActionMounted('updateEmail')
        ->unmountAction()
        ->assertSet('mountedActions', []);

    expect(ActionLog::has('updateEmail'))->toBeFalse()
        ->and($this->user->fresh()->email)->toBe('old@example.com');
});

it('requires verification again for every new attempt', function (): void {
    livewire(AccountSettings::class)
        ->mountAction('updateEmail')
        ->fillForm(['password' => 'secret-password'])
        ->callMountedAction()
        ->fillForm(['email' => 'new@example.com'])
        ->callMountedAction()
        ->mountAction('updateEmail')
        ->assertActionMounted(['updateEmail', 'identityVerification']);
});

it('preserves the protected action form validation without consuming the grant', function (): void {
    $component = livewire(AccountSettings::class)
        ->mountAction('updateEmail')
        ->fillForm(['password' => 'secret-password'])
        ->callMountedAction()
        ->fillForm(['email' => 'not-an-email'])
        ->callMountedAction()
        ->assertHasFormErrors(['email' => 'email'])
        ->assertActionMounted('updateEmail');

    expect(ActionLog::has('updateEmail'))->toBeFalse()
        ->and(DB::table('identity_verification_grants')->whereNotNull('consumed_at')->count())->toBe(0);

    $component
        ->fillForm(['email' => 'new@example.com'])
        ->callMountedAction()
        ->assertHasNoErrors();

    expect($this->user->fresh()->email)->toBe('new@example.com')
        ->and(DB::table('identity_verification_grants')->whereNotNull('consumed_at')->count())->toBe(1);
});

it('executes a modal-less protected action class only after verification', function (): void {
    livewire(AccountSettings::class)
        ->mountAction('deleteAccount')
        ->assertActionMounted(['deleteAccount', 'identityVerification']);

    expect(ActionLog::has('deleteAccount'))->toBeFalse();

    livewire(AccountSettings::class)
        ->mountAction('deleteAccount')
        ->fillForm(['password' => 'secret-password'])
        ->callMountedAction()
        ->assertSet('mountedActions', []);

    expect(ActionLog::$entries)->toBe(['deleteAccount']);
});

it('issues grants bound to the user, panel guard and the action purpose', function (): void {
    livewire(AccountSettings::class)
        ->mountAction('deleteAccount')
        ->fillForm(['password' => 'secret-password'])
        ->callMountedAction();

    $grant = DB::table('identity_verification_grants')->sole();

    expect($grant->purpose)->toBe('delete_account')
        ->and($grant->method)->toBe('password')
        ->and($grant->guard)->toBe('web')
        ->and($grant->authenticatable_type)->toBe(User::class)
        ->and($grant->authenticatable_id)->toBe((string) $this->user->getKey())
        ->and($grant->consumed_at)->not->toBeNull();
});

it('rate limits failed verification attempts', function (): void {
    $component = livewire(AccountSettings::class)->mountAction('updateEmail');

    foreach (range(1, 5) as $attempt) {
        $component
            ->fillForm(['password' => 'wrong-password'])
            ->callMountedAction()
            ->assertHasFormErrors(['password']);
    }

    $component
        ->fillForm(['password' => 'secret-password'])
        ->callMountedAction()
        ->assertHasFormErrors(['password'])
        ->assertActionMounted(['updateEmail', 'identityVerification']);

    expect($component->errors()->first('mountedActions.1.data.password'))->toStartWith('Too many attempts.')
        ->and(DB::table('identity_verification_grants')->count())->toBe(0);
});

it('does not execute an unauthorized protected action even after verification', function (): void {
    AccountSettings::$canChangeSecuritySettings = false;

    livewire(AccountSettings::class)
        ->mountAction('changeSecuritySettings')
        ->assertActionNotMounted('changeSecuritySettings');

    expect(ActionLog::$entries)->toBe([]);
});
