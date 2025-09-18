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
        Log::info("Affinity: {$charName} corporation changed {$oldCorpStr} → {$newCorpStr}", [
            'character_id' => $event->character_id,
            'old_corp_id'  => $event->old_corporation_id,
            'new_corp_id'  => $event->new_corporation_id,
            'owners'       => $ownerIdsArray,
        ]);

        // Example: persist internal alert (uncomment if you have the model/table)
        // \CapsuleCmdr\Affinity\Models\AffinityAlert::create([
        //     'type'         => 'corp_change',
        //     'character_id' => $event->character_id,
        //     'old_value'    => (string) $event->old_corporation_id,
        //     'new_value'    => (string) $event->new_corporation_id,
        //     'message'      => "{$charName} changed corporation: {$oldCorpStr} → {$newCorpStr}",
        //     'user_ids'     => $ownerIdsArray,
        // ]);

        // Optional Discord webhook
        // if ($webhook = config('affinity.discord_webhook_url')) {
        //     try {
        //         Http::asJson()->post($webhook, [
        //             'content' => "**Corporation Change Detected**\n"
        //                        ."**Character:** {$charName} ({$event->character_id})\n"
        //                        ."**From:** {$oldCorpStr}\n"
        //                        ."**To:** {$newCorpStr}",
        //         ]);
        //     } catch (\Throwable $e) {
        //         Log::error('Affinity Discord webhook failed', ['error' => $e->getMessage()]);
        //     }
        // }
    }
}
