<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Actions;

use Filament\Actions\Action;
use Syriable\Filament\Plugins\IdentityVerificationAction\Actions\Contracts\ProtectedByIdentityVerification;
use Syriable\Filament\Plugins\IdentityVerificationAction\Concerns\RequiresIdentityVerification;

/**
 * A plain Filament action that can require identity verification, for inline
 * definitions: `ProtectedAction::make('updateEmail')->requiresIdentityVerification('update_email')`.
 *
 * To protect another action type (e.g. `DeleteAction`), extend it and use the
 * `RequiresIdentityVerification` trait in the same way as this class.
 */
class ProtectedAction extends Action implements ProtectedByIdentityVerification
{
    use RequiresIdentityVerification;
}
