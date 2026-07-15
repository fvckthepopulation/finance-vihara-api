<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['code', 'name', 'type', 'parent_code', 'entity_id', 'is_header', 'note', 'active', 'is_system'])]
class Account extends Model
{
    protected function casts(): array
    {
        return [
            'is_header' => 'boolean',
            'active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function entity(): BelongsTo { return $this->belongsTo(Entity::class); }
}
