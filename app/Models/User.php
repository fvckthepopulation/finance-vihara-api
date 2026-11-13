<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'entity_id', 'scope_type', 'active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /** Seluruh yayasan & vihara — admin tertinggi. */
    public const SCOPE_GLOBAL = 'global';

    /** Seluruh vihara di bawah satu yayasan + data level yayasan. */
    public const SCOPE_ENTITY = 'entity';

    /** Hanya vihara yang di-assign lewat pivot user_vihara (1..n). */
    public const SCOPE_VIHARA = 'vihara';

    public const SCOPE_TYPES = [self::SCOPE_GLOBAL, self::SCOPE_ENTITY, self::SCOPE_VIHARA];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /**
     * Vihara yang boleh diakses user ber-scope `vihara`. Untuk scope lain
     * pivot ini diabaikan — lihat allowedViharaIds().
     */
    public function viharas(): BelongsToMany
    {
        return $this->belongsToMany(Vihara::class, 'user_vihara')->withTimestamps();
    }

    public function isGlobalScope(): bool
    {
        return $this->scope_type === self::SCOPE_GLOBAL;
    }

    public function isEntityScope(): bool
    {
        return $this->scope_type === self::SCOPE_ENTITY;
    }

    public function isViharaScope(): bool
    {
        return $this->scope_type === self::SCOPE_VIHARA;
    }

    /**
     * Daftar id vihara yang boleh diakses.
     *
     * null = tanpa batas (scope global). Array kosong = tidak boleh melihat
     * vihara mana pun (mis. user scope `vihara` yang belum di-assign, atau
     * user scope `entity` tanpa entity_id) — ini sengaja menutup akses,
     * bukan membukanya.
     *
     * @return array<int, int>|null
     */
    public function allowedViharaIds(): ?array
    {
        if ($this->isGlobalScope()) {
            return null;
        }

        if ($this->isEntityScope()) {
            if ($this->entity_id === null) {
                return [];
            }

            return Vihara::query()
                ->where('entity_id', $this->entity_id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return $this->viharas()->pluck('viharas.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Boleh melihat baris level yayasan (vihara_id NULL, mis. jurnal pusat
     * dan laporan konsolidasi). User ter-scope vihara tidak boleh.
     */
    public function canSeeYayasanLevel(): bool
    {
        return $this->isGlobalScope() || $this->isEntityScope();
    }

    /** $viharaId null = baris level yayasan. */
    public function canAccessVihara(?int $viharaId): bool
    {
        if ($viharaId === null) {
            return $this->canSeeYayasanLevel();
        }

        $allowed = $this->allowedViharaIds();

        return $allowed === null || in_array($viharaId, $allowed, true);
    }

    /**
     * Kompat untuk klien lama: satu vihara "utama". Hanya terisi bila user
     * benar-benar terkunci ke tepat satu vihara.
     */
    public function getPrimaryViharaIdAttribute(): ?int
    {
        if (! $this->isViharaScope()) {
            return null;
        }

        $ids = $this->allowedViharaIds() ?? [];

        return count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
