<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Tests\Fixtures;

/**
 * Records which protected callbacks ran during a test.
 */
class ActionLog
{
    /** @var array<string> */
    public static array $entries = [];

    public static function record(string $entry): void
    {
        static::$entries[] = $entry;
    }

    public static function reset(): void
    {
        static::$entries = [];
    }

    public static function has(string $entry): bool
    {
        return in_array($entry, static::$entries, true);
    }
}
