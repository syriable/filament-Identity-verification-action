<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction;

use Filament\Contracts\Plugin;
use Filament\Panel;
use InvalidArgumentException;

/**
 * Optional per-panel settings for identity verification. The plugin does not
 * register pages, resources or global hooks; protected actions work in any
 * Livewire component. Registering it lets a panel override the defaults.
 */
class IdentityVerificationActionPlugin implements Plugin
{
    public const ID = 'filament-identity-verification-action';

    protected ?string $defaultMethod = null;

    protected ?int $grantLifetime = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }

    public function getId(): string
    {
        return static::ID;
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}

    /**
     * The verification method used by protected actions in this panel that do
     * not name one explicitly.
     */
    public function defaultMethod(?string $method): static
    {
        $this->defaultMethod = $method;

        return $this;
    }

    public function getDefaultMethod(): ?string
    {
        return $this->defaultMethod;
    }

    /**
     * How many seconds a grant issued in this panel stays valid.
     */
    public function grantLifetime(?int $seconds): static
    {
        if (($seconds !== null) && ($seconds < 1)) {
            throw new InvalidArgumentException('The grant lifetime must be at least one second.');
        }

        $this->grantLifetime = $seconds;

        return $this;
    }

    public function getGrantLifetime(): ?int
    {
        return $this->grantLifetime;
    }
}
