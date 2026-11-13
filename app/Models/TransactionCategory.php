<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master Kategori Biaya (FV-29).
 *
 * `code` sengaja memakai kunci yang sama dengan konstanta lama
 * (Pembayaran::CATEGORIES), karena kolom pembayaran.category menyimpan kode
 * itu. Jadi mengubah master ini tidak memutus transaksi yang sudah ada.
 */
#[Fillable([
    'type', 'code', 'name', 'description', 'default_debit_code', 'active',
])]
class TransactionCategory extends Model
{
    use SerializesDatesInAppTimezone;

    public const TYPE_PENGELUARAN = 'pengeluaran';

    public const TYPE_PENERIMAAN = 'penerimaan';

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function names(): HasMany
    {
        return $this->hasMany(TransactionName::class, 'category', 'code')
            ->where('type', $this->type ?? self::TYPE_PENGELUARAN);
    }

    public function defaultDebitAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_debit_code', 'code');
    }

    /**
     * Label satu kategori pengeluaran menurut master, dengan cadangan ke
     * konstanta lama supaya baris lama berkategori yang sudah dihapus dari
     * master tetap terbaca.
     */
    public static function labelPengeluaran(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        static $cache = null;
        $cache ??= static::labelsPengeluaran();

        return $cache[$code] ?? (Pembayaran::CATEGORIES[$code]['label'] ?? null);
    }

    /** Peta kode → label untuk kategori pengeluaran yang masih dipakai. */
    public static function labelsPengeluaran(): array
    {
        return static::query()
            ->where('type', self::TYPE_PENGELUARAN)
            ->pluck('name', 'code')
            ->all();
    }
}
