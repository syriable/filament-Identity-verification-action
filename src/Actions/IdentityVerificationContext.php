<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Actions;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Syriable\Filament\Plugins\IdentityVerificationAction\IdentityVerificationActionPlugin;

/**
 * Resolves the authentication context of the current request: the current
 * Filament panel's guard when inside a panel, the application's default guard
 * otherwise, and the per-panel plugin settings when the plugin is registered.
 *
 * @internal
 */
final class IdentityVerificationContext
{
    public static function guard(): string
    {
        return Filament::getCurrentPanel()?->getAuthGuard() ?? Auth::getDefaultDriver();
    }

    public static function user(): ?Authenticatable
    {
        return Auth::guard(self::guard())->user();
    }

    public static function plugin(): ?IdentityVerificationActionPlugin
    {
        $panel = Filament::getCurrentPanel();

        if (! $panel?->hasPlugin(IdentityVerificationActionPlugin::ID)) {
            return null;
        }

        $plugin = $panel->getPlugin(IdentityVerificationActionPlugin::ID);

        return $plugin instanceof IdentityVerificationActionPlugin ? $plugin : null;
    }

    public static function defaultMethod(): string
    {
        $method = self::plugin()?->getDefaultMethod() ?? config('filament-identity-verification-action.default_method', 'password');

        return is_string($method) ? $method : 'password';
    }

    public static function grantLifetime(): ?int
    {
        return self::plugin()?->getGrantLifetime();
    }
}
