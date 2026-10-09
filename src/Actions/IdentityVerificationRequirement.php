<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Actions;

/**
 * Immutable, server-side description of what a protected action requires.
 * It is configured in PHP and never derived from client input.
 */
final class IdentityVerificationRequirement
{
    /**
     * @param  string|null  $method  Null uses the panel plugin's default, then the configured default.
     */
    public function __construct(
        public readonly string $purpose,
        public readonly ?string $method = null,
        public readonly bool $singleUse = true,
    ) {}

    public function resolveMethod(): string
    {
        return $this->method ?? IdentityVerificationContext::defaultMethod();
    }
}
