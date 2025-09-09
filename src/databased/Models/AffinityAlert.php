<?php

namespace CapsuleCmdr\Affinity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Seat\Web\Models\User;

class AffinityAlert extends Model
{
    protected $table = 'affinity_alert';

    protected $fillable = [
        'title',
        'status',
        'acknowledged_by_id',
        'acknowledge_date',
        'associated_entity_id',
    ];

    protected $casts = [
        'acknowledge_date' => 'datetime',
    ];

    public function associatedEntity(): BelongsTo
    {
        return $this->belongsTo(AffinityEntity::class, 'associated_entity_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_id');
    }
}
