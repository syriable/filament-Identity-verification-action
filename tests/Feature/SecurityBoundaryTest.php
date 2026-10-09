<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Syriable\Filament\Plugins\IdentityVerificationAction\Actions\Contracts\ProtectedByIdentityVerification;
use Syriable\Filament\Plugins\IdentityVerificationAction\Tests\Fixtures\AccountSettings;
use Syriable\Filament\Plugins\IdentityVerificationAction\Tests\Fixtures\ActionLog;
use Syriable\Filament\Plugins\IdentityVerificationAction\Tests\Fixtures\User;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\GrantSubject;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\VerificationManager;

use function Pest\Laravel\actingAs;

const GRANT = ProtectedByIdentityVerification::GRANT_ARGUMENT;

beforeEach(function (): void {
    ActionLog::reset();
    AccountSettings::$canChangeSecuritySettings = true;

    Filament::setCurrentPanel('admin');

    $this->user = User::createWithPassword('old@example.com');

    actingAs($this->user);
});

function issueGrant(User $user, string $purpose, string $guard = 'web', ?int $lifetime = null): string
{
    return app(VerificationManager::class)
        ->issue(GrantSubject::fromUser($user, $guard), $purpose, 'password', $lifetime)
        ->token;
}

/**
 * Forges the Livewire state a malicious client could send, bypassing the UI, and
 * asks the server to execute the protected action directly.
 *
 * @param  array<string, mixed>  $arguments
 * @param  array<string, mixed>  $data
 */
function executeDirectly(string $action, array $arguments = [], array $data = [], array $callArguments = []): mixed
{
    return Livewire::test(AccountSettings::class)
        ->set('mountedActions', [[
            'name' => $action,
            'arguments' => $arguments,
            'context' => [],
            'data' => $data,
        ]])
        ->call('callMountedAction', $callArguments);
}

it('rejects direct execution without a grant', function (): void {
    executeDirectly('updateEmail', data: ['email' => 'attacker@example.com'])
        ->assertNotified('Identity verification required');

    executeDirectly('deleteAccount');
    executeDirectly('changeSecuritySettings');

    expect(ActionLog::$entries)->toBe([])
        ->and($this->user->fresh()->email)->toBe('old@example.com');
});

it('rejects a client-controlled boolean or forged token as proof of verification', function (mixed $forgedGrant): void {
    executeDirectly('deleteAccount', [GRANT => $forgedGrant]);
    executeDirectly('deleteAccount', callArguments: [GRANT => $forgedGrant]);

    expect(ActionLog::$entries)->toBe([]);
})->with([
    'true' => [true],
    '1' => ['1'],
    'array' => [['valid' => true]],
    'random token' => [Str::random(64)],
    'empty' => [''],
]);

it('accepts direct execution with a valid grant for the right purpose', function (): void {
    executeDirectly('deleteAccount', [GRANT => issueGrant($this->user, 'delete_account')]);

    expect(ActionLog::$entries)->toBe(['deleteAccount']);
});

it('rejects a grant issued for another purpose', function (): void {
    $grant = issueGrant($this->user, 'update_email');

    executeDirectly('deleteAccount', [GRANT => $grant]);

    expect(ActionLog::$entries)->toBe([])
        ->and(DB::table('identity_verification_grants')->whereNotNull('consumed_at')->count())->toBe(0);
});

it('rejects a grant issued to another user', function (): void {
    $otherUser = User::createWithPassword('other@example.com');

    executeDirectly('deleteAccount', [GRANT => issueGrant($otherUser, 'delete_account')]);

    expect(ActionLog::$entries)->toBe([]);
});

it('rejects a grant issued for another guard', function (): void {
    executeDirectly('deleteAccount', [GRANT => issueGrant($this->user, 'delete_account', guard: 'api')]);

    expect(ActionLog::$entries)->toBe([]);
});

it('rejects an expired grant', function (): void {
    $grant = issueGrant($this->user, 'delete_account', lifetime: 60);

    $this->travel(61)->seconds();

    executeDirectly('deleteAccount', [GRANT => $grant]);

    expect(ActionLog::$entries)->toBe([]);
});

it('rejects a revoked grant', function (): void {
    $grant = issueGrant($this->user, 'delete_account');

    app(VerificationManager::class)->revokeAll($this->user);

    executeDirectly('deleteAccount', [GRANT => $grant]);

    expect(ActionLog::$entries)->toBe([]);
});

it('rejects a replayed single-use grant', function (): void {
    $grant = issueGrant($this->user, 'delete_account');

    executeDirectly('deleteAccount', [GRANT => $grant]);
    executeDirectly('deleteAccount', [GRANT => $grant]);
    executeDirectly('deleteAccount', callArguments: [GRANT => $grant]);

    expect(ActionLog::$entries)->toBe(['deleteAccount']);
});

it('rejects replaying the grant captured from a completed UI flow', function (): void {
    $component = Livewire::test(AccountSettings::class)
        ->mountAction('updateEmail')
        ->fillForm(['password' => 'secret-password'])
        ->callMountedAction();

    $grant = $component->get('mountedActions.0.arguments.' . GRANT);

    $component
        ->fillForm(['email' => 'new@example.com'])
        ->callMountedAction();

    executeDirectly('updateEmail', [GRANT => $grant], ['email' => 'attacker@example.com']);

    expect($this->user->fresh()->email)->toBe('new@example.com');
});

it('does not let a grant for one protected action be redirected to another by renaming the mounted action', function (): void {
    $component = Livewire::test(AccountSettings::class)
        ->mountAction('exportData')
        ->fillForm(['password' => 'secret-password'])
        ->callMountedAction()
        ->assertActionMounted('exportData');

    $component
        ->set('mountedActions.0.name', 'deleteAccount')
        ->call('callMountedAction');

    expect(ActionLog::$entries)->toBe([]);
});

it('allows a non single-use grant to be reused until it expires', function (): void {
    $grant = issueGrant($this->user, 'export_data', lifetime: 60);

    executeDirectly('exportData', [GRANT => $grant]);
    executeDirectly('exportData', [GRANT => $grant]);

    $this->travel(61)->seconds();

    executeDirectly('exportData', [GRANT => $grant]);

    expect(ActionLog::$entries)->toBe(['exportData', 'exportData']);
});

it('does not let a valid grant bypass authorization, and leaves the grant unconsumed', function (): void {
    $grant = issueGrant($this->user, 'security_settings');

    AccountSettings::$canChangeSecuritySettings = false;

    executeDirectly('changeSecuritySettings', [GRANT => $grant]);

    expect(ActionLog::$entries)->toBe([])
        ->and(DB::table('identity_verification_grants')->whereNotNull('consumed_at')->count())->toBe(0);

    AccountSettings::$canChangeSecuritySettings = true;

    executeDirectly('changeSecuritySettings', [GRANT => $grant]);

    expect(ActionLog::$entries)->toBe(['changeSecuritySettings']);
});

it('does not mount the protected form with an invalid grant', function (): void {
    Livewire::test(AccountSettings::class)
        ->mountAction('updateEmail', [GRANT => Str::random(64)])
        ->assertActionMounted(['updateEmail', 'identityVerification']);

    expect(ActionLog::has('updateEmail.fill'))->toBeFalse();
});

it('cannot mount the verification action on its own', function (): void {
    Livewire::test(AccountSettings::class)
        ->mountAction('identityVerification')
        ->assertSet('mountedActions', []);
});

it('rejects verification for an unauthenticated session', function (): void {
    $component = Livewire::test(AccountSettings::class)
        ->mountAction('deleteAccount');

    auth()->logout();

    $component
        ->fillForm(['password' => 'secret-password'])
        ->callMountedAction()
        ->assertHasFormErrors(['password']);

    expect(ActionLog::$entries)->toBe([])
        ->and(DB::table('identity_verification_grants')->count())->toBe(0);
});

it('revokes outstanding grants on logout', function (): void {
    $grant = issueGrant($this->user, 'delete_account');

    auth()->logout();

    expect(app(VerificationManager::class)->isValid($grant, $this->user, 'web', 'delete_account'))->toBeFalse();
});
