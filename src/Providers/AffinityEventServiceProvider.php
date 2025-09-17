<?php

namespace CapsuleCmdr\Affinity\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use CapsuleCmdr\Affinity\Events\CorporationChanged;
use CapsuleCmdr\Affinity\Listeners\HandleCorporationChanged;

class AffinityEventServiceProvider extends ServiceProvider
{
    protected $listen = [
        CorporationChanged::class => [
            HandleCorporationChanged::class,
        ],
    ];
}
