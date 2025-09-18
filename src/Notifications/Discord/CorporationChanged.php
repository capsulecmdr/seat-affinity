<?php

namespace CapsuleCmdr\Affinity\Notifications\Discord;

use Seat\Notifications\Notifications\AbstractDiscordNotification;
use Seat\Notifications\Services\Discord\Messages\DiscordMessage;
use Seat\Notifications\Services\Discord\Messages\DiscordEmbed;
use Seat\Notifications\Services\Discord\Messages\DiscordEmbedField;
use Carbon\Carbon;

class CorporationChanged extends AbstractDiscordNotification
{
    public function __construct(
        public string $character,        // Character name
        public int    $character_id,     // Character ID
        public string $old_corporation,  // Old corp name
        public int    $old_corporation_id,
        public string $new_corporation,  // New corp name
        public int    $new_corporation_id,
        public ?Carbon $at = null,       // Timestamp (default now)
    ) {
        $this->at = $at ?? now();
    }

    // Tip: methods on DiscordMessage mirror an embed-style builder.
    protected function populateMessage(DiscordMessage $message, mixed $notifiable): DiscordMessage
    {
        return $message->embed(function (DiscordEmbed $embed) {
            $embed->title('Corporation Change Detected')
                ->color(0x3498db) // nice blue
                ->timestamp($this->at)
                ->author($this->character, null, "https://images.evetech.net/characters/{$this->character_id}/portrait?size=64")
                ->field(function (DiscordEmbedField $field) {
                    $field->name('Character')->value("{$this->character} ({$this->character_id})");
                })
                ->field(function (DiscordEmbedField $field) {
                    $field->name('From')->value("{$this->old_corporation} ({$this->old_corporation_id})");
                })
                ->field(function (DiscordEmbedField $field) {
                    $field->name('To')->value("{$this->new_corporation} ({$this->new_corporation_id})");
                });
        });
    }
}