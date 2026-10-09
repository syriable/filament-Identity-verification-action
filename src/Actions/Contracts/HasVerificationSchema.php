<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Actions\Contracts;

use Filament\Schemas\Components\Component;

/**
 * Implemented by custom verification methods (in addition to the core
 * `VerificationMethod` contract) to describe the fields the verification
 * modal should render for them.
 */
interface HasVerificationSchema
{
    /**
     * @return array<Component>
     */
    public function getVerificationSchema(): array;

    /**
     * The name of the field that failed verification errors are attached to.
     */
    public function getVerificationErrorField(): string;
}
