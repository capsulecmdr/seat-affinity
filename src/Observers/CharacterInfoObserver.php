<?php

namespace CapsuleCmdr\Affinity\Observers;

use Seat\Eveapi\Models\Character\CharacterInfo;
use CapsuleCmdr\Affinity\Events\CorporationChanged;

class CharacterInfoObserver
{
    public function updated(CharacterInfo $character): void
    {
        // Only act when corporation_id actually changed
        if (! $character->wasChanged('corporation_id')) {
            return;
        }

        // Avoid “first write from null” noise
        $old = $character->getOriginal('corporation_id');
        $new = $character->corporation_id;
        if (is_null($old) || is_null($new) || $old == $new) {
            return;
        }

        // only fire for characters that are owned by a SeAT user
        if (! $character->ownership()->exists()) {
            return;
        }

        event(new CorporationChanged($character->character_id, $old, $new));
    }
}
