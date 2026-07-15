<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'code', 'date', 'method', 'source_account_code', 'category', 'name',
    'entity_id', 'vihara_id', 'bank_account_id', 'payee_id', 'payee_name', 'approved_by_name',
    'debit_account_code',
    'reference', 'amount', 'description', 'attachment_path',
    'status', 'journal_entry_id',
    'created_by_user_id', 'posted_by_user_id', 'posted_at',
])]
class Pembayaran extends Model
{
    use SerializesDatesInAppTimezone;

    protected $table = 'pembayaran';

    /**
     * Kategori pengeluaran vihara — mengikuti COA ISAK 35 (nonprofit).
     * `default_debit` adalah saran akun beban (operator boleh override).
     */
    public const CATEGORIES = [
        'biaya_operasional' => ['label' => 'Biaya Operasional',            'default_debit' => '5110'],
        'biaya_paramita'    => ['label' => 'Biaya Paramita',               'default_debit' => '5120'],
        'biaya_bhikkhu'     => ['label' => 'Biaya Bhikkhu',                'default_debit' => '5210'],
        'biaya_perayaan'    => ['label' => 'Biaya Perayaan',               'default_debit' => '5220'],
        'komisi_platform'   => ['label' => 'Beban Komisi Platform KM',     'default_debit' => '7140'],
        'utang_lain'        => ['label' => 'Pelunasan Utang Lain-lain',    'default_debit' => '2110'],
        'biaya_lainnya'     => ['label' => 'Biaya Lainnya',                'default_debit' => '5110'],
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

    public function payee(): BelongsTo
    {
        return $this->belongsTo(Payee::class);
    }

    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'source_account_code', 'code');
    }

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'debit_account_code', 'code');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
