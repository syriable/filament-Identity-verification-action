<?php

declare(strict_types=1);

arch('the verification core does not depend on Filament or Livewire')
    ->expect('Syriable\Filament\Plugins\IdentityVerificationAction\Verification')
    ->not->toUse(['Filament', 'Livewire']);

arch('the verification core does not depend on the Filament integration layer')
    ->expect('Syriable\Filament\Plugins\IdentityVerificationAction\Verification')
    ->not->toUse([
        'Syriable\Filament\Plugins\IdentityVerificationAction\Actions',
        'Syriable\Filament\Plugins\IdentityVerificationAction\Concerns',
    ]);

arch('source files declare strict types')
    ->expect('Syriable\Filament\Plugins\IdentityVerificationAction')
    ->toUseStrictTypes();

arch('no debugging calls are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'logger', 'info'])
    ->not->toBeUsed();
