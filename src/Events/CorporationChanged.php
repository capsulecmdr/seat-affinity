<?php

namespace CapsuleCmdr\Affinity\Events;

use Illuminate\Queue\SerializesModels;

class CorporationChanged
{
    use SerializesModels;

    public int $character_id;
    public int $old_corporation_id;
    public int $new_corporation_id;

    public function __construct(int $character_id, int $old_corp_id, int $new_corp_id)
    {
        $this->character_id = $character_id;
        $this->old_corporation_id = $old_corp_id;
        $this->new_corporation_id = $new_corp_id;
    }
}
