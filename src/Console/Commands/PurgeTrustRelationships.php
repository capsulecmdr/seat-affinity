<?php

namespace CapsuleCmdr\Affinity\Console\Commands;

use Illuminate\Console\Command;
use CapsuleCmdr\Affinity\Models\AffinityTrustRelationship;

class PurgeTrustRelationships extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'affinity:trust:delete {--force : Force delete without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Purge all records from the affinity_trust_relationship table';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->option('force')) {
            if (! $this->confirm('Are you sure you want to purge ALL trust relationships? This cannot be undone.')) {
                $this->info('Operation cancelled.');
                return Command::SUCCESS;
            }
        }

        $count = AffinityTrustRelationship::count();

        AffinityTrustRelationship::truncate();

        $this->info("Purged {$count} trust relationship(s) from the table.");

        return Command::SUCCESS;
    }
}
