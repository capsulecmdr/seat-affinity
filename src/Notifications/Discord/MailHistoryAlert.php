<?php

namespace CapsuleCmdr\Affinity\Notifications\Discord;

use Seat\Notifications\Notifications\AbstractDiscordNotification;
use Seat\Notifications\Services\Discord\Messages\DiscordMessage;
use Seat\Notifications\Services\Discord\Messages\DiscordEmbed;
use Seat\Notifications\Services\Discord\Messages\DiscordEmbedField;

class MailHistoryAlert extends AbstractDiscordNotification
{
    public function __construct(
        public string $user,
        public string $correspondant,
        public ?\Carbon\Carbon $at = null,
    ) {}

    // Tip: methods on DiscordMessage mirror an embed-style builder.
    protected function populateMessage(DiscordMessage $message, mixed $notifiable): DiscordMessage
    {       
        $this->user = $this->user . "'s";

        return $message
            ->embed(function (DiscordEmbed $embed){
                $embed->timestamp($this->at);
                $embed->author("Affinity Intel");
                $embed->color(15548997);
                $embed->title('Mail History Alert');
                $embed->description($this->user . ' mail history with ' . $this->correspondant . ' exceeds alerting thresholds.');                
            })

            ->success();
    }
}