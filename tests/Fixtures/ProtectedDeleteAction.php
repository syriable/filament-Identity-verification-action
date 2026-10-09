<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Tests\Fixtures;

use Filament\Actions\Action;
use Syriable\Filament\Plugins\IdentityVerificationAction\Actions\Contracts\ProtectedByIdentityVerification;
use Syriable\Filament\Plugins\IdentityVerificationAction\Concerns\RequiresIdentityVerification;

/**
 * A modal-less action class protected through the trait, as a host
 * application would define it.
 */
class ProtectedDeleteAction extends Action implements ProtectedByIdentityVerification
{
    use RequiresIdentityVerification;

    public static function getDefaultName(): ?string
    {
        return 'deleteAccount';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->action(function (): void {
            ActionLog::record('deleteAccount');
        });
    }
}
