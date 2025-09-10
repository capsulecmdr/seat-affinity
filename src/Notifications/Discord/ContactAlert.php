<?php

namespace CapsuleCmdr\Affinity\Notifications\Discord;

use Seat\Notifications\Notifications\AbstractDiscordNotification;
use Seat\Notifications\Services\Discord\Messages\DiscordMessage;
use Seat\Notifications\Services\Discord\Messages\DiscordEmbed;
use Seat\Notifications\Services\Discord\Messages\DiscordEmbedField;

class ContactAlert extends AbstractDiscordNotification
{
    public function __construct(
        public string $user,
        public string $contact_name,
        public string $contact_type,
        public ?\Carbon\Carbon $at = null,
    ) {}

    // Tip: methods on DiscordMessage mirror an embed-style builder.
    protected function populateMessage(DiscordMessage $message, mixed $notifiable): DiscordMessage
    {

        return $message
            ->embed(function (DiscordEmbed $embed){
                $embed->timestamp($this->at);
                $embed->author("Affinity Intel");
                $embed->color(15548997);
                $embed->title('Suspicious Contact Alert');
                $embed->description($this->user . ' has an established ' . $this->contact_type . ' contact for ' . $this->contact_name . ' which exceeds alerting threshold.');                
            })

            ->success();
    }
}