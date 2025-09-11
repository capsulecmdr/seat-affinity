<?php

namespace CapsuleCmdr\Affinity\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Contacts\CharacterContact;
use Seat\Web\Models\User;
use CapsuleCmdr\Affinity\Models\AffinityTrustRelationship;

class CheckTrustRelationships extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Example: php artisan affinity:check-trusts
     */
    protected $signature = 'affinity:check-trusts';

    /**
     * The console command description.
     */
    protected $description = 'Check all user contacts for trust relationships >= 3 and log errors.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Scanning users, characters, and contacts for trust relationships >= 3...');

        User::with('characters')->chunk(50, function ($users) {
            foreach ($users as $user) {
                foreach ($user->characters as $character) {
                    $contacts = CharacterContact::where('character_id', $character->character_id)->get();

                    foreach ($contacts as $contact) {
                        $trust = AffinityTrustRelationship::where('affinity_entity_id', $contact->contact_id)->first();
                        if ($trust && $trust->affinity_trust_class_id >= 3) {
                            $msg = sprintf(
                                "User %d, Character %d, Contact %d has trust classification %d (>=3)",
                                $user->id,
                                $character->character_id,
                                $contact->contact_id,
                                $trust->affinity_trust_class_id
                            );

                            Log::error($msg);
                            $this->error($msg);
                        }else{
                            $msg = sprintf(
                                "User %d, Character %d, Contact %d has trust classification %d",
                                $user->id,
                                $character->character_id,
                                $contact->contact_id,
                                $trust->affinity_trust_class_id
                            );
                            
                            $this->info($msg);
                        }
                    }
                }
            }
        });

        $this->info('Trust check completed.');
        return Command::SUCCESS;
    }
}
