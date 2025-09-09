<?php

namespace CapsuleCmdr\Affinity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffinityTrustRelationship extends Model
{
    protected $table = 'affinity_trust_relationship';

    protected $fillable = [
        'affinity_entity_id',
        'affinity_trust_classification_id',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(AffinityEntity::class, 'affinity_entity_id');
    }

    public function classification(): BelongsTo
    {
        return $this->belongsTo(AffinityTrustClassification::class, 'affinity_trust_classification_id');
    }
}
