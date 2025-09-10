<?php

namespace CapsuleCmdr\Affinity\Facades;

use Illuminate\Support\Facades\Facade;

class AffinitySettings extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'affinity.settings';
    }
}
