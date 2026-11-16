<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Keanggotaan satu yayasan di satu grup, berlaku [start_date, end_date]. */
class KonsolidasiGroupMember extends Model
{
    protected $fillable = ['konsolidasi_group_id', 'entity_id', 'start_date', 'end_date'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(KonsolidasiGroup::class, 'konsolidasi_group_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
