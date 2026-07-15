<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'code', 'name', 'type', 'categories', 'expense_account_code',
    'bank_name', 'bank_account_number', 'bank_account_holder', 'branch',
    'npwp', 'contact_person', 'phone', 'address',
    'entity_id', 'active',
])]
class Payee extends Model
{
    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'active' => 'boolean',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
