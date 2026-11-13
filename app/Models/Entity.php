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

    /**
     * Unit Umum/Pusat yayasan (P-01) — dibuat otomatis, satu per yayasan,
     * untuk transaksi yang bukan milik vihara mana pun.
     */
    public function unitUmum(): ?Vihara
    {
        return $this->viharas()->where('is_umum', true)->first();
    }

    /** Buat unit Umum kalau yayasan ini belum punya. */
    public function pastikanUnitUmum(): Vihara
    {
        $ada = $this->unitUmum();
        if ($ada !== null) {
            return $ada;
        }

        $dasar = substr(($this->code ?: 'YYS').'-UMUM', 0, 16);
        $kode = $dasar;
        $n = 1;
        while (Vihara::where('code', $kode)->exists()) {
            $kode = substr($dasar, 0, 14).$n;
            $n++;
        }

        return $this->viharas()->create([
            'is_umum' => true,
            'code' => $kode,
            'name' => 'Umum/Pusat '.$this->legal_name,
            'bank_name' => '',
            'bank_account_number' => '',
            'bank_account_holder' => '',
            'active' => true,
        ]);
    }

    /** Rekening bank & kas milik yayasan ini (FV-36). */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(ViharaBankAccount::class);
    }

    /** User yang terikat ke yayasan ini (scope entity maupun vihara). */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
