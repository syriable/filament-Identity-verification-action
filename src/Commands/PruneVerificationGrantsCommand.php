<?php

declare(strict_types=1);

namespace Syriable\Filament\Plugins\IdentityVerificationAction\Commands;

use Illuminate\Console\Command;
use Syriable\Filament\Plugins\IdentityVerificationAction\Verification\VerificationManager;

final class PruneVerificationGrantsCommand extends Command
{
    protected $signature = 'identity-verification:prune';

    protected $description = 'Delete expired, consumed and revoked identity verification grants';

    public function handle(VerificationManager $manager): int
    {
        $count = $manager->prune();

        $this->components->info("Pruned {$count} identity verification grant(s).");

        return self::SUCCESS;
    }
}
