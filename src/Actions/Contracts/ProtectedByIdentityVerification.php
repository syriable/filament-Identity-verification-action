<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Actions\Contracts;

use Syriable\Filament\Plugins\IdentityVerificationAction\Actions\IdentityVerificationRequirement;

/**
 * Implemented by actions that use the `RequiresIdentityVerification` trait.
 */
interface ProtectedByIdentityVerification
{
    /**
     * The action argument that carries the grant token. Its value is client
     * controlled and is only ever accepted after server-side validation.
     */
    public const GRANT_ARGUMENT = 'identityVerificationGrant';

    public function getIdentityVerificationRequirement(): ?IdentityVerificationRequirement;

    public function isIdentityVerificationPending(): bool;
}
