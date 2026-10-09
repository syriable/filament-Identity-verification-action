<?php

declare(strict_types=1);

use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Syriable\Filament\Plugins\IdentityVerificationAction\Actions\Contracts\ProtectedByIdentityVerification;
use Syriable\Filament\Plugins\IdentityVerificationAction\Tests\Fixtures\ActionLog;
use Syriable\Filament\Plugins\IdentityVerificationAction\Tests\Fixtures\User;
use Syriable\Filament\Plugins\IdentityVerificationAction\Tests\Fixtures\UsersTable;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    ActionLog::reset();

    Filament::setCurrentPanel('admin');

    $this->user = User::createWithPassword('admin@example.com');
    $this->target = User::createWithPassword('target@example.com');

    actingAs($this->user);
});

it('protects table record actions and keeps the record after verification', function (): void {
    $component = livewire(UsersTable::class)
        ->mountAction(TestAction::make('resetUser')->table($this->target))
        ->assertActionMounted([TestAction::make('resetUser')->table($this->target), 'identityVerification'])
        ->fillForm(['password' => 'secret-password'])
        ->callMountedAction()
        ->assertActionMounted(TestAction::make('resetUser')->table($this->target));

    expect($component->get('mountedActions.0.context'))->toMatchArray(['table' => true, 'recordKey' => (string) $this->target->getKey()])
        ->and(ActionLog::$entries)->toBe([]);

    $component->callMountedAction();

    expect(ActionLog::$entries)->toBe(['resetUser:target@example.com']);
});

it('rejects direct execution of a table record action without a grant', function (): void {
    livewire(UsersTable::class)
        ->set('mountedActions', [[
            'name' => 'resetUser',
            'arguments' => [ProtectedByIdentityVerification::GRANT_ARGUMENT => str_repeat('a', 64)],
            'context' => ['table' => true, 'recordKey' => (string) $this->target->getKey()],
        ]])
        ->call('callMountedAction');

    expect(ActionLog::$entries)->toBe([]);
});

it('cannot add identity verification to an action that does not use the trait', function (): void {
    Action::make('plain')->requiresIdentityVerification(purpose: 'plain');
})->throws(BadMethodCallException::class);
