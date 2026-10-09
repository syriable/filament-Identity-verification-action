<?php

declare(strict_types=1);

use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Methods\PasswordVerification;

return [

    /*
    |--------------------------------------------------------------------------
    | Default verification method
    |--------------------------------------------------------------------------
    |
    | Used by protected actions that do not name a method. A registered
    | panel plugin can override it per panel.
    |
    */

    'default_method' => 'password',

    /*
    |--------------------------------------------------------------------------
    | Verification methods
    |--------------------------------------------------------------------------
    |
    | Method key => class implementing
    | Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Contracts\VerificationMethod.
    | Classes are resolved from the container.
    |
    */

    'methods' => [
        'password' => PasswordVerification::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Grants
    |--------------------------------------------------------------------------
    |
    | `lifetime` is the number of seconds a grant stays valid after a
    | successful verification. `connection` (null = default) and `table`
    | configure the database store.
    |
    */

    'grants' => [
        'lifetime' => 300,
        'connection' => null,
        'table' => 'identity_verification_grants',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    |
    | Failed verification attempts allowed per user (and guard) within the
    | decay window, in seconds.
    |
    */

    'rate_limiting' => [
        'max_attempts' => 5,
        'decay_seconds' => 60,
    ],

];
