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
    'type', 'code', 'name', 'restriction', 'description', 'default_debit_code',
    'bank_account_code', 'active',
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

    /** Jenis pembatasan kategori dana (FV-49). */
    public const PEMBATASAN = [
        'tp' => 'Tanpa Pembatasan',
        'dp' => 'Dengan Pembatasan',
        'suspense' => 'Belum Teridentifikasi',
    ];

    /**
     * Akun pendapatan kategori ini per kanal penerimaan.
     *
     * Dikembalikan sebagai peta metode => kode akun, supaya layar master bisa
     * MENAMPILKAN akun yang akan dipakai — keluhan FV-49 adalah kolomnya
     * selalu "—" padahal akunnya memang ditentukan sistem, bukan kosong.
     *
     * Kategori suspense tidak mengikuti kanal: akunnya tetap (2310).
     *
     * @return array<string, string|null>|null null untuk kategori pengeluaran
     */
    public function akunPerMetode(): ?array
    {
        if ($this->type !== self::TYPE_PENERIMAAN) {
            return null;
        }
        if ($this->restriction === 'suspense' || $this->restriction === null) {
            $akun = $this->default_debit_code ?: '2310';

            return ['auto_credit' => $akun, 'transfer' => $akun, 'tunai' => $akun];
        }

        $digit = $this->restriction === 'dp' ? '2' : '1';

        return [
            'auto_credit' => '41'.$digit.'0',
            'transfer' => '42'.$digit.'0',
            'tunai' => '43'.$digit.'0',
        ];
    }

    /**
     * Akun bank yang sah untuk kategori pengeluaran ini (FV-56).
     *
     * null = tidak dibatasi. Saat ini seluruh kategori pengeluaran diisi
     * 1120 Bank Operasional: dana donasi di 1130 dipindahkan dulu lewat
     * transfer antar rekening, dan pembatasannya dilacak lewat aset neto.
     */
    public static function akunBankUntuk(?string $kategoriPengeluaran): ?string
    {
        if ($kategoriPengeluaran === null || $kategoriPengeluaran === '') {
            return null;
        }

        return static::query()
            ->where('type', self::TYPE_PENGELUARAN)
            ->where('code', $kategoriPengeluaran)
            ->value('bank_account_code');
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
        return static::labels(self::TYPE_PENGELUARAN);
    }

    /** Peta kode → label satu jenis master ('pengeluaran' | 'penerimaan'). */
    public static function labels(string $type): array
    {
        return static::query()
            ->where('type', $type)
            ->pluck('name', 'code')
            ->all();
    }

    /**
     * Label satu kategori penerimaan menurut master, dengan cadangan ke
     * konstanta lama (Penerimaan::CATEGORIES) supaya baris lama tetap
     * terbaca walau kategorinya sudah dihapus dari master.
     */
    public static function labelPenerimaan(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        static $cache = null;
        $cache ??= static::labels(self::TYPE_PENERIMAAN);

        return $cache[$code] ?? (Penerimaan::CATEGORIES[$code]['label'] ?? null);
    }
}
