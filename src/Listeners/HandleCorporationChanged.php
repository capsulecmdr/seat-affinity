<?php

namespace CapsuleCmdr\Affinity\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use CapsuleCmdr\Affinity\Events\CorporationChanged;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Eveapi\Models\RefreshToken;     // ⬅️ add
use Seat\Web\Models\User;

class HandleCorporationChanged implements ShouldQueue
{
    use InteractsWithQueue;

    // Make sure your worker listens to this (e.g., --queue=notifications,default)
    public $queue = 'notifications';

    public function handle(CorporationChanged $event): void
    {
        Log::warning("Affinity: Corp Changed Listener Fired.");
        // Load character
        $char = CharacterInfo::find($event->character_id);
        if (! $char) {
            Log::warning('Affinity corp change: character not found', [
                'character_id' => $event->character_id,
            ]);
            return;
        }

        // Resolve corp names (fallback to IDs if missing)
        $oldCorp = $event->old_corporation_id
            ? CorporationInfo::find($event->old_corporation_id)
            : null;

        $newCorp = $event->new_corporation_id
            ? CorporationInfo::find($event->new_corporation_id)
            : null;

        $charName   = $char->name ?? (string) $event->character_id;
        $oldCorpStr = $oldCorp->name ?? (string) $event->old_corporation_id;
        $newCorpStr = $newCorp->name ?? (string) $event->new_corporation_id;

        // Resolve owning users (prefer RefreshToken, fallback to users->characters)
        $ownerUserIds = RefreshToken::where('character_id', $event->character_id)
            ->pluck('user_id')->unique()->values();

        if ($ownerUserIds->isEmpty()) {
            $ownerUserIds = User::whereHas('characters', function ($q) use ($event) {
                    $q->where('character_id', $event->character_id);
                })
                ->pluck('id')->unique()->values();
        }

        $ownerIdsArray = $ownerUserIds->all();
        $owners = !empty($ownerIdsArray) ? User::whereIn('id', $ownerIdsArray)->get() : collect();

        // Persist/log/notify (swap to Log::info; ERROR is too noisy for normal state changes)
        Log::warning("Affinity: {$charName} corporation changed {$oldCorpStr} → {$newCorpStr}", [
            'character_id' => $event->character_id,
            'old_corp_id'  => $event->old_corporation_id,
            'new_corp_id'  => $event->new_corporation_id,
            'owners'       => $ownerIdsArray,
        ]);

        affinity_notify(
        'affinity.notification_corp_changed',           // <-- must match your config key
        [
            $charName,                                   // character (string)
            (int) $event->character_id,                  // character_id
            (string) $oldCorpStr,                        // old_corporation (name or ID-as-string)
            (int) $event->old_corporation_id,            // old_corporation_id
            (string) $newCorpStr,                        // new_corporation (name or ID-as-string)
            (int) $event->new_corporation_id,            // new_corporation_id
            \Carbon\Carbon::now(),                       // at (Carbon)
        ],
        false,                                           // queued; set true for sync test
        'discord'                                        // optional: restrict to Discord integrations
    );
    }
}
