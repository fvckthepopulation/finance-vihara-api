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
     * Kategori penerimaan dana vihara — mengikuti COA ISAK 35 (nonprofit).
     * Dana tanpa pembatasan: operasional + paramita; dengan pembatasan:
     * bhikkhu + perayaan. Donasi yang belum jelas peruntukannya masuk
     * suspense 2310 sampai teridentifikasi.
     */
    public const CATEGORIES = [
        'dana_operasional'   => ['label' => 'Dana Operasional (Tanpa Pembatasan)', 'default_credit' => '4110'],
        'dana_paramita'      => ['label' => 'Dana Paramita (Tanpa Pembatasan)',    'default_credit' => '4120'],
        'dana_bhikkhu'       => ['label' => 'Dana Bhikkhu (Dengan Pembatasan)',    'default_credit' => '4210'],
        'dana_perayaan'      => ['label' => 'Dana Perayaan (Dengan Pembatasan)',   'default_credit' => '4220'],
        'donasi_belum_teridentifikasi' => ['label' => 'Donasi Belum Teridentifikasi', 'default_credit' => '2310'],
    ];

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
