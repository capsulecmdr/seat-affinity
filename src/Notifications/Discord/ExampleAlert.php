<?php

namespace CapsuleCmdr\Affinity\Notifications\Discord;

use Seat\Notifications\Notifications\AbstractDiscordNotification;
use Seat\Notifications\Services\Discord\Messages\DiscordMessage;
use Seat\Notifications\Services\Discord\Messages\DiscordEmbed;
use Seat\Notifications\Services\Discord\Messages\DiscordEmbedField;

class ExampleAlert extends AbstractDiscordNotification
{
    public function __construct(
        public bool $enabled,
        public ?string $reason = null,
        public ?string $description = null,
        public ?string $by = null,
        public ?\Carbon\Carbon $at = null,
    ) {}

    // Tip: methods on DiscordMessage mirror an embed-style builder.
    protected function populateMessage(DiscordMessage $message, mixed $notifiable): DiscordMessage
    {
        $status = $this->enabled ? 'ENABLED' : 'DISABLED';
        

        return $message
            ->embed(function (DiscordEmbed $embed){
                $embed->timestamp($this->at);
                $embed->author($this->by);
                if($this->enabled){
                    $embed->color(16747520);
                    $embed->title($this->reason);
                    $embed->description($this->description);
                }else{
                    $embed->color(3329330);
                    $embed->title('**Complete:** ' . $this->reason);
                    $embed->description('Return to normal operating proceedures');
                }
                
            })

            ->success();
    }
}