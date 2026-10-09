<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Tests\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements FilamentUser
{
    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public static function createWithPassword(string $email, string $password = 'secret-password'): self
    {
        return self::query()->create([
            'name' => 'Test User',
            'email' => $email,
            'password' => $password,
        ]);
    }
}
