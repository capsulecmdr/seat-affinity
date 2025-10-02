<?php

namespace CapsuleCmdr\Affinity\Observers;

use Seat\Eveapi\Models\Character\CharacterAffiliation;
use CapsuleCmdr\Affinity\Events\CorporationChanged;

class CharacterAffiliationObserver
{
    /**
     * Fire when the affiliation row is updated and corporation_id really changed.
     */
    public function updated(CharacterAffiliation $aff): void
    {
        if (! $aff->wasChanged('corporation_id')) {
            return;
        }

        $old = $aff->getOriginal('corporation_id');
        $new = $aff->corporation_id;

        // Optional: if you *do* want to alert on first write (null -> value), remove this guard.
        if ($old === null || $new === null || (int) $old === (int) $new) {
            return;
        }

        event(new CorporationChanged(
            (int) $aff->character_id,
            (int) $old,
            (int) $new
        ));
    }

    /**
     * (Optional) Handle creation where corporation_id is set at insert time.
     * If you want to alert on first insert, enable this and remove the null guard above.
     */
    public function created(CharacterAffiliation $aff): void
    {
        // Example: if you want to emit on create when a corp is already present
        // if ($aff->corporation_id) {
        //     event(new CorporationChanged((int) $aff->character_id, 0, (int) $aff->corporation_id));
        // }
    }
}
