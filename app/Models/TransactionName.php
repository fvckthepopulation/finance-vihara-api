<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Master nama transaksi (Nama Penerimaan / Nama Pengeluaran) — lookup untuk
 * dropdown form input. `category` mengacu ke key kategori di
 * Penerimaan::CATEGORIES / Pembayaran::CATEGORIES.
 *
 * `vihara_id` NULL berarti item berlaku untuk SEMUA vihara; terisi berarti
 * hanya muncul untuk vihara itu.
 */
#[Fillable(['type', 'category', 'vihara_id', 'name', 'active'])]
class TransactionName extends Model
{
    use SerializesDatesInAppTimezone;

    public function vihara(): BelongsTo
    {
        return $this->belongsTo(Vihara::class);
    }

    /** Item umum + item milik vihara-vihara ini. $viharaIds null = tanpa batas. */
    public function scopeVisibleForViharas($query, ?array $viharaIds)
    {
        if ($viharaIds === null) {
            return $query;
        }

        return $query->where(function ($q) use ($viharaIds) {
            $q->whereNull('vihara_id')->orWhereIn('vihara_id', $viharaIds);
        });
    }

    /**
     * Apakah nama ini boleh dipakai untuk transaksi di vihara tersebut?
     *
     * Dipakai SESUDAH vihara transaksi diketahui — aturan `exists` di
     * validasi hanya bisa memeriksa type+category, karena vihara baru
     * ditetapkan ViharaScopeService setelah validasi berjalan. Tanpa
     * pemeriksaan ini, item khusus satu vihara masih bisa dipakai vihara
     * lain lewat API walau tidak pernah muncul di dropdownnya.
     */
    public static function usableForVihara(string $type, string $name, int $viharaId): bool
    {
        return static::query()
            ->where('active', true)
            ->where('type', $type)
            ->where('name', $name)
            ->where(function ($q) use ($viharaId) {
                $q->whereNull('vihara_id')->orWhere('vihara_id', $viharaId);
            })
            ->exists();
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}
