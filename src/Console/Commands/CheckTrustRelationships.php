<?php

namespace CapsuleCmdr\Affinity\Console\Commands;

use CapsuleCmdr\Affinity\Models\AffinityEntity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Contacts\CharacterContact;
use Seat\Web\Models\User;
use CapsuleCmdr\Affinity\Models\AffinityTrustRelationship;

use Illuminate\Notifications\AnonymousNotifiable;
use Seat\Notifications\Models\NotificationGroup;
use Seat\Notifications\Traits\NotificationDispatchTool;

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
                        //find the associated affinityentity
                        $affinityEntity = AffinityEntity::where('eve_id',$contact->contact_id)->first();
                        
                        //if no entity exists skip *for now
                        if(!$affinityEntity){
                            $msg = sprintf(
                                "User %d, Character %d, Contact %d has no associated affinity entity",
                                $user->id,
                                $character->character_id,
                                $contact->contact_id
                            );
                            $this->warn($msg);
                            continue;
                        }

                        $trust = AffinityTrustRelationship::where('affinity_entity_id', $affinityEntity->id)->first();
                        
                        //default to neut
                        
                        
                        if ($trust && $trust->affinity_trust_class_id >= 3) {

                            //fire alert
                            $user = "";
                            $contact_name = "";
                            $contact_type = "";
                            $user = "";
                            $user = "";

                            $groups = NotificationGroup::whereHas(
                                'alerts',
                                fn ($q) => $q->where('alert', 'osmm.maintenance_toggled')
                            )->get();

                            if ($groups->isEmpty()) return;

                            //loop through all notification groups and fire events
                            foreach($groups as $group){
                                //loop through all integrations within the group
                                foreach(($group->integrations) as $integration){
                                    $notification = config('notifications.alerts')['affinity.alert_contact']['handlers']['discord'];
                                    $setting = (array) $integration->settings;
                                    $key = array_key_first($setting);
                                    $route = $setting[$key];
                                    $anon = (new AnonymousNotifiable)->route($integration->type, $route);
                                    Notification::sendNow($anon,new $notification(
                                        $user,
                                        $contact_name,
                                        $contact_type,
                                        now()
                                    ));
                                }
                            }


                            $msg = sprintf(
                                "User %d, Character %d, Contact %d has trust classification %d (>=3)",
                                $user->id,
                                $character->character_id,
                                $contact->contact_id,
                                $trust->affinity_trust_class_id
                            );

                            Log::error($msg);
                            $this->error($msg);
                        }elseif($trust && $trust->affinity_trust_class_id >= 1){
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
