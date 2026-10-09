<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Exceptions;

enum VerificationFailure: string
{
    case Unauthenticated = 'unauthenticated';
    case InvalidCredentials = 'invalid_credentials';
    case TooManyAttempts = 'too_many_attempts';
    case MethodUnavailable = 'method_unavailable';
}
