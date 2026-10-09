<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Tests\Fixtures;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Component;
use Syriable\Filament\Plugins\IdentityVerificationAction\Actions\ProtectedAction;

/**
 * A table with a protected record action, to cover table action contexts.
 */
class UsersTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function table(Table $table): Table
    {
        return $table
            ->query(User::query())
            ->columns([
                TextColumn::make('email'),
            ])
            ->recordActions([
                ProtectedAction::make('resetUser')
                    ->requiresIdentityVerification(purpose: 'reset_user')
                    ->requiresConfirmation()
                    ->action(fn (User $record) => ActionLog::record("resetUser:{$record->email}")),
            ]);
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>
                {{ $this->table }}

                <x-filament-actions::modals />
            </div>
            BLADE;
    }
}
