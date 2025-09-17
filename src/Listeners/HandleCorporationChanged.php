<?php

namespace CapsuleCmdr\Affinity\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use CapsuleCmdr\Affinity\Events\CorporationChanged;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Web\Models\User;

class HandleCorporationChanged implements ShouldQueue
{
    use InteractsWithQueue;

    public $queue = 'default';

    public function handle(CorporationChanged $event): void
    {
        $char = CharacterInfo::with('ownership')->find($event->character_id);
        if (! $char) return;

        $oldCorp = CorporationInfo::find($event->old_corporation_id);
        $newCorp = CorporationInfo::find($event->new_corporation_id);

        $charName   = $char->name ?? $event->character_id;
        $oldCorpStr = $oldCorp->name ?? $event->old_corporation_id;
        $newCorpStr = $newCorp->name ?? $event->new_corporation_id;

        // Find owning users (usually one, but be robust)
        $ownerUserIds = $char->ownership()->pluck('user_id')->all();
        $owners = User::whereIn('id', $ownerUserIds)->get();

        // 3a) Optional: persist an internal alert row (if you have a table)
        // \CapsuleCmdr\Affinity\Models\AffinityAlert::create([
        //     'type' => 'corp_change',
        //     'character_id' => $event->character_id,
        //     'old_value' => (string)$event->old_corporation_id,
        //     'new_value' => (string)$event->new_corporation_id,
        //     'message' => "{$charName} changed corporation: {$oldCorpStr} → {$newCorpStr}",
        //     'user_ids' => json_encode($ownerUserIds),
        // ]);

        Log::error("Affinity: {$charName} corp change {$oldCorpStr} → {$newCorpStr}", [
            'character_id' => $event->character_id,
            'old_corp_id'  => $event->old_corporation_id,
            'new_corp_id'  => $event->new_corporation_id,
            'owners'       => $ownerUserIds,
        ]);

        // 3b) Optional: Discord webhook
        // $webhook = config('affinity.discord_webhook_url');
        // if ($webhook) {
        //     $content = sprintf(
        //         "**Corporation Change Detected**\n**Character:** %s (%d)\n**From:** %s\n**To:** %s",
        //         $charName,
        //         $event->character_id,
        //         $oldCorpStr,
        //         $newCorpStr
        //     );

        //     try {
        //         Http::asJson()->post($webhook, [
        //             'content' => $content
        //         ]);
        //     } catch (\Throwable $e) {
        //         Log::error('Affinity Discord webhook failed', ['error' => $e->getMessage()]);
        //     }
        // }
    }
}
