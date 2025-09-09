<?php

namespace CapsuleCmdr\Affinity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AffinityTrustClassification extends Model
{
    protected $table = 'affinity_trust_classification';

    protected $fillable = ['title'];

    public function relationships(): HasMany
    {
        return $this->hasMany(AffinityTrustRelationship::class, 'affinity_trust_classification_id');
    }
}
