<?php

namespace App\Models;

use App\Models\Concerns\HasApproval;
use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'entity_id', 'vihara_id', 'pic_id', 'subtype', 'source_bank_account_id',
    'cash_account_code', 'type', 'movement_date', 'amount',
    'counterpart_account_code', 'recipient', 'category', 'reference', 'description',
    'attachment_path', 'status', 'journal_entry_id', 'created_by_user_id', 'posted_at',
])]
class PettyCashMovement extends Model
{
    use HasApproval;
    use SerializesDatesInAppTimezone;

    public const APPROVAL_RESOURCE_TYPE = 'petty_cash';

    /**
     * Petty cash kategori vihara. Free-text di DB column — dropdown ini
     * cuma untuk UX standardisasi. `default_counterpart` adalah saran
     * akun beban (operator boleh override).
     */
    public const CATEGORIES = [
        'operasional' => ['label' => 'Keperluan Operasional', 'default_counterpart' => '6100'],
        'paramita'    => ['label' => 'Keperluan Paramita',    'default_counterpart' => '5300'],
        'bhikkhu'     => ['label' => 'Keperluan Bhikkhu',     'default_counterpart' => '5100'],
        'perayaan'    => ['label' => 'Keperluan Perayaan',    'default_counterpart' => '5200'],
        'lainnya'     => ['label' => 'Lainnya',               'default_counterpart' => '6300'],
    ];

    public function runPostAction(User $user): void
    {
        if ($this->status !== 'draft') {
            throw new \RuntimeException("Petty cash movement already posted.");
        }

        DB::transaction(function () use ($user) {
            $isRefill = $this->type === 'refill';
            $debitCode = $isRefill ? $this->cash_account_code : $this->counterpart_account_code;
            $creditCode = $isRefill ? $this->counterpart_account_code : $this->cash_account_code;

            if ($this->subtype !== null) {
                $picName = $this->pic?->name ?? $this->recipient;
                $desc = $isRefill
                    ? "Pengisian Kas Kecil — {$picName}"
                    : "Pemakaian Kas Kecil — {$picName}";
            } else {
                $desc = $isRefill
                    ? "Refill Kas Kecil — {$this->recipient}"
                    : "Pengeluaran Kas Kecil — " . ($this->category ?? 'Lainnya');
            }

            $journal = JournalEntry::create([
                'entity_id' => $this->entity_id,
                'vihara_id' => $this->vihara_id,
                'entry_date' => $this->movement_date,
                'reference_type' => 'petty_cash',
                'reference_id' => $this->id,
                'description' => $desc,
                'total_debit' => $this->amount,
                'total_credit' => $this->amount,
                'posted_at' => now(),
                'posted_by_user_id' => $user->id,
            ]);

            JournalLine::create([
                'journal_entry_id' => $journal->id,
                'account_code' => $debitCode,
                'debit' => $this->amount,
                'credit' => 0,
                'description' => $this->description ?? $desc,
            ]);
            JournalLine::create([
                'journal_entry_id' => $journal->id,
                'account_code' => $creditCode,
                'debit' => 0,
                'credit' => $this->amount,
                'description' => $this->description ?? $desc,
            ]);

            $this->update([
                'status' => 'posted',
                'posted_at' => now(),
                'journal_entry_id' => $journal->id,
            ]);
        });
    }

    protected function casts(): array
    {
        return [
            'movement_date' => 'date',
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

    public function pic(): BelongsTo
    {
        return $this->belongsTo(PettyCashPic::class, 'pic_id');
    }

    /** Sumber dana topup; null = dari kas penerimaan langsung (1115). */
    public function sourceBankAccount(): BelongsTo
    {
        return $this->belongsTo(ViharaBankAccount::class, 'source_bank_account_id');
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cash_account_code', 'code');
    }

    public function counterpartAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counterpart_account_code', 'code');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
