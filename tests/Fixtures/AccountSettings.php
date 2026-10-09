<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Tests\Fixtures;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Component;
use Syriable\Filament\Plugins\IdentityVerificationAction\Actions\ProtectedAction;

/**
 * A Livewire component standing in for a host application's account page.
 */
class AccountSettings extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public static bool $canChangeSecuritySettings = true;

    public function updateEmailAction(): Action
    {
        return ProtectedAction::make('updateEmail')
            ->requiresIdentityVerification(purpose: 'update_email')
            ->schema([
                TextInput::make('email')
                    ->email()
                    ->required(),
            ])
            ->fillForm(function (): array {
                ActionLog::record('updateEmail.fill');

                return ['email' => Filament::auth()->user()?->getAttribute('email')];
            })
            ->before(fn () => ActionLog::record('updateEmail.before'))
            ->action(function (array $data): void {
                ActionLog::record('updateEmail');

                Filament::auth()->user()?->update(['email' => $data['email']]);
            });
    }

    public function deleteAccountAction(): Action
    {
        return ProtectedDeleteAction::make()
            ->requiresIdentityVerification(purpose: 'delete_account');
    }

    public function changeSecuritySettingsAction(): Action
    {
        return ProtectedAction::make('changeSecuritySettings')
            ->requiresIdentityVerification(purpose: 'security_settings')
            ->authorize(fn (): bool => static::$canChangeSecuritySettings)
            ->requiresConfirmation()
            ->action(fn () => ActionLog::record('changeSecuritySettings'));
    }

    public function exportDataAction(): Action
    {
        return ProtectedAction::make('exportData')
            ->requiresIdentityVerification(purpose: 'export_data', singleUse: false)
            ->requiresConfirmation()
            ->action(fn () => ActionLog::record('exportData'));
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>
                {{ $this->updateEmailAction }}
                {{ $this->deleteAccountAction }}
                {{ $this->changeSecuritySettingsAction }}
                {{ $this->exportDataAction }}

                <x-filament-actions::modals />
            </div>
            BLADE;
    }
}
