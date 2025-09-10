<?php

namespace CapsuleCmdr\Affinity\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeEntities extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Example: php artisan affinity:entities:delete
     */
    protected $signature = 'affinity:entities:delete {--force : Force deletion without confirmation}';

    /**
     * The console command description.
     */
    protected $description = 'Delete all records from the entities table';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->option('force')) {
            if (! $this->confirm('Are you sure you want to delete ALL records from the entities table?')) {
                $this->info('Aborted.');
                return Command::SUCCESS;
            }
        }

        DB::table('entities')->truncate();

        $this->info('All records in the entities table have been deleted.');

        return Command::SUCCESS;
    }
}
