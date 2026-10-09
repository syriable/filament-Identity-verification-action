<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\GrantSubject;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\Stores\DatabaseVerificationGrantStore;

/*
 * Several OS processes race to consume the same grant against a shared on-disk
 * database. Exactly one of them may succeed.
 */
it('lets exactly one of several concurrent processes consume a grant', function (): void {
    $directory = sys_get_temp_dir() . '/identity-verification-' . bin2hex(random_bytes(6));
    File::ensureDirectoryExists($directory);
    $database = "{$directory}/grants.sqlite";
    touch($database);

    config()->set('database.connections.concurrency', [
        'driver' => 'sqlite',
        'database' => $database,
        'foreign_key_constraints' => false,
        'busy_timeout' => 10_000,
        'journal_mode' => 'wal',
    ]);

    DB::connection('concurrency')->getSchemaBuilder()->create('identity_verification_grants', function ($table): void {
        $table->id();
        $table->char('token_hash', 64)->unique();
        $table->string('guard');
        $table->string('authenticatable_type');
        $table->string('authenticatable_id');
        $table->string('purpose', 100);
        $table->string('method', 100);
        $table->timestamp('expires_at');
        $table->timestamp('consumed_at')->nullable();
        $table->timestamp('revoked_at')->nullable();
        $table->timestamp('created_at')->nullable();
    });

    $subject = new GrantSubject('web', 'App\\Models\\User', '1');
    $tokenHash = hash('sha256', 'token');

    (new DatabaseVerificationGrantStore(app('db'), 'concurrency', 'identity_verification_grants'))
        ->create($tokenHash, $subject, 'delete_account', 'password', now()->addMinutes(5));

    DB::disconnect('concurrency');

    $workers = 8;
    $startAt = microtime(true) + 0.5;
    $pids = [];

    for ($worker = 0; $worker < $workers; $worker++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork.');
        }

        if ($pid === 0) {
            // Child: open its own connection, wait for the common start time, race.
            $pdo = new PDO("sqlite:{$database}");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA busy_timeout = 10000');

            $connections = new ConnectionResolver([
                'worker' => new SQLiteConnection($pdo, $database, '', ['name' => 'worker']),
            ]);
            $store = new DatabaseVerificationGrantStore($connections, 'worker', 'identity_verification_grants');

            time_sleep_until($startAt);

            $consumed = $store->consume($tokenHash, $subject, 'delete_account', now());

            file_put_contents("{$directory}/worker-{$worker}", $consumed ? '1' : '0');

            // Leave without running the test runner's shutdown handlers.
            posix_kill(getmypid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $results = array_map(
        fn (int $worker): string => (string) @file_get_contents("{$directory}/worker-{$worker}"),
        range(0, $workers - 1),
    );

    File::deleteDirectory($directory);

    expect($results)->each->toBeIn(['0', '1'])
        ->and(array_count_values($results)['1'] ?? 0)->toBe(1);
})->skip(fn (): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'Requires the pcntl and posix extensions.');
