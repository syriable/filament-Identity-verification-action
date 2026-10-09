<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use Syriable\Filament\Plugins\IdentityVerificationAction\IdentityVerificationActionPlugin;

class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->default()
            ->plugin(IdentityVerificationActionPlugin::make());
    }
}
