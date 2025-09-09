<?php

namespace CapsuleCmdr\Affinity\Models;

use Illuminate\Database\Eloquent\Model;

class AffinitySetting extends Model
{
    protected $table = 'affinity_setting';

    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'string',
    ];
}
