<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Yayasan — puncak hirarki tenant. Menaungi 0..n vihara. */
#[Fillable(['code', 'legal_name', 'npwp', 'address', 'active'])]
class Entity extends Model
{
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function viharas(): HasMany
    {
        return $this->hasMany(Vihara::class);
    }

    /** User yang terikat ke yayasan ini (scope entity maupun vihara). */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
