<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'code', 'date', 'method', 'destination_account_code', 'category', 'name',
    'entity_id', 'vihara_id', 'bank_account_id', 'source_payee_id', 'source_name', 'received_by',
    'credit_account_code',
    'reference', 'amount', 'description', 'attachment_path',
    'status', 'journal_entry_id',
    'created_by_user_id', 'posted_by_user_id', 'posted_at',
])]
class Penerimaan extends Model
{
    use SerializesDatesInAppTimezone;

    protected $table = 'penerimaan';

    /**
     * Kategori penerimaan dana vihara — mengikuti COA ISAK 35 v2.
     * Dana tanpa pembatasan (TP): operasional + paramita; dengan pembatasan
     * (DP): bhikkhu + perayaan. Akun kredit TIDAK lagi fix per kategori —
     * ditentukan kanal penerimaan (method) via creditCodeFor(). Donasi yang
     * belum jelas peruntukannya masuk suspense 2310 sampai teridentifikasi.
     */
    public const CATEGORIES = [
        'dana_operasional'   => ['label' => 'Dana Operasional (Tanpa Pembatasan)', 'restriction' => 'tp'],
        'dana_paramita'      => ['label' => 'Dana Paramita (Tanpa Pembatasan)',    'restriction' => 'tp'],
        'dana_bhikkhu'       => ['label' => 'Dana Bhikkhu (Dengan Pembatasan)',    'restriction' => 'dp'],
        'dana_perayaan'      => ['label' => 'Dana Perayaan (Dengan Pembatasan)',   'restriction' => 'dp'],
        'donasi_belum_teridentifikasi' => ['label' => 'Donasi Belum Teridentifikasi', 'restriction' => null, 'default_credit' => '2310'],
    ];

    /** Kanal penerimaan → prefix akun pendapatan donasi (COA 4xxx). */
    public const CHANNEL_PREFIX = [
        'auto_credit' => '41', // via platform KM
        'transfer'    => '42', // transfer bank langsung
        'tunai'       => '43', // tunai / kotak donasi
    ];

    /**
     * Akun kredit penerimaan = kanal × pembatasan: 41x0 KM, 42x0 transfer,
     * 43x0 tunai; digit ke-3 = 1 (TP) / 2 (DP). Kategori tanpa restriction
     * (suspense) memakai default_credit tetap (2310).
     */
    public static function creditCodeFor(string $category, ?string $method): string
    {
        $meta = self::CATEGORIES[$category];
        if (($meta['restriction'] ?? null) === null) {
            return $meta['default_credit'];
        }
        $prefix = self::CHANNEL_PREFIX[$method] ?? self::CHANNEL_PREFIX['transfer'];

        return $prefix . ($meta['restriction'] === 'dp' ? '2' : '1') . '0';
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function vihara(): BelongsTo
    {
        return $this->belongsTo(Vihara::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(ViharaBankAccount::class, 'bank_account_id');
    }

    public function sourcePayee(): BelongsTo
    {
        return $this->belongsTo(Payee::class, 'source_payee_id');
    }

    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'destination_account_code', 'code');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'credit_account_code', 'code');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** Donasi KM Finance yang membuat penerimaan ini (null = input manual). */
    public function kmDonation(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(KmDonation::class, 'penerimaan_id');
    }
}
