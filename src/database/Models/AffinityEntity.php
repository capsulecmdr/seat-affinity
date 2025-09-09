<?php

namespace CapsuleCmdr\Affinity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AffinityEntity extends Model
{
    protected $table = 'affinity_entity';

    protected $fillable = [
        'type', 'name', 'eve_id',
    ];

    public function trustRelationships(): HasMany
    {
        return $this->hasMany(AffinityTrustRelationship::class, 'affinity_entity_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(AffinityAlert::class, 'associated_entity_id');
    }
}
