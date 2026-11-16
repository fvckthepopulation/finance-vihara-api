<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grup Konsolidasi (FV-66): sekumpulan yayasan yang dilaporkan bersama,
 * masing-masing dengan periode keanggotaan. Pembukuan tiap yayasan tetap
 * terpisah; grup hanya lapisan laporan.
 */
class KonsolidasiGroup extends Model
{
    protected $fillable = ['name', 'description', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(KonsolidasiGroupMember::class)->orderBy('start_date');
    }

    /** User non-global yang diberi akses ke grup ini. */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'konsolidasi_group_user');
    }

    /** Grup yang boleh dilihat user: global = semua; lainnya = yang diberikan. */
    public static function untukUser(User $user)
    {
        return static::query()
            ->when(! $user->isGlobalScope(), fn ($q) => $q->whereHas('users', fn ($u) => $u->where('users.id', $user->id)))
            ->orderBy('name');
    }
}
