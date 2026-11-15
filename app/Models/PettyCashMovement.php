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
    'counterpart_account_code', 'recipient', 'category', 'name', 'reference', 'description',
    'notes', 'attachment_path', 'status', 'journal_entry_id', 'created_by_user_id', 'posted_at',
])]
class PettyCashMovement extends Model
{
    use HasApproval;
    use SerializesDatesInAppTimezone;

    public const APPROVAL_RESOURCE_TYPE = 'petty_cash';

    /**
     * Kode kategori kas kecil sebelum memakai master (FV-82). Masih diterima
     * API supaya klien lama tidak patah, lalu diterjemahkan ke kode master
     * Kategori Biaya — akunnya sama persis.
     */
    public const KATEGORI_LAMA = [
        'operasional' => 'biaya_operasional',
        'paramita' => 'biaya_paramita',
        'bhikkhu' => 'biaya_bhikkhu',
        'perayaan' => 'biaya_perayaan',
        'lainnya' => 'biaya_lainnya',
    ];

    /**
     * Deskripsi jurnal kas kecil (FV-87): menyebut UNTUK APA uangnya, bukan
     * hanya nama PIC — supaya daftar Jurnal & Buku Besar bisa dibaca dan
     * dicari tanpa membuka baris jurnalnya.
     *
     *   Penggunaan: <nama pengeluaran> — Pemakaian Kas Kecil — <PIC> — <kategori> — No. Bukti <ref>
     *   Pengisian : Pengisian Kas Kecil — <PIC> — dari <Dana Tunai | rekening> — <ref> — <catatan>
     */
    public function deskripsiJurnal(): string
    {
        $pic = $this->pic?->name ?? $this->recipient;

        if ($this->subtype === 'topup' || $this->type === 'refill') {
            $sumber = $this->sourceBankAccount ? 'dari ' . $this->sourceBankAccount->label() : 'dari Dana Tunai';

            return self::gabung([
                'Pengisian Kas Kecil', $pic, $sumber, $this->reference, $this->notes,
            ]);
        }

        $kategori = $this->category
            ? (TransactionCategory::query()
                ->where('type', TransactionCategory::TYPE_PENGELUARAN)
                ->where('code', $this->category)
                ->value('name') ?? $this->category)
            : null;

        return self::gabung([
            // Mutasi lama belum punya Nama Pengeluaran — keperluannya ada di
            // description (teks bebas sebelum FV-82).
            $this->name ?: $this->description,
            'Pemakaian Kas Kecil',
            $pic,
            $kategori,
            $this->reference ? "No. Bukti {$this->reference}" : null,
        ]);
    }

    /** Deskripsi jurnal pembalik: sama dengan aslinya, diawali "Pembatalan". */
    public static function deskripsiPembalik(string $deskripsiAsli, string $alasan): string
    {
        return self::gabung(["Pembatalan: {$deskripsiAsli}", $alasan !== '' ? "Alasan: {$alasan}" : null]);
    }

    /** @param  array<int, string|null>  $bagian */
    private static function gabung(array $bagian): string
    {
        $bagian = array_values(array_filter(
            array_map(fn ($b) => $b === null ? null : trim((string) $b), $bagian),
            fn ($b) => $b !== null && $b !== '',
        ));

        return mb_substr(implode(' — ', $bagian), 0, 500);
    }

    public function runPostAction(User $user): void
    {
        if ($this->status !== 'draft') {
            throw new \RuntimeException('Mutasi kas kecil tidak dalam status draft.');
        }

        DB::transaction(function () use ($user) {
            $isRefill = $this->type === 'refill';
            $debitCode = $isRefill ? $this->cash_account_code : $this->counterpart_account_code;
            $creditCode = $isRefill ? $this->counterpart_account_code : $this->cash_account_code;
            $desc = $this->deskripsiJurnal();

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

            // Baris jurnal memakai deskripsi yang sama. Catatan penginput
            // tidak menimpanya (FV-51); catatan tersimpan di barisnya sendiri.
            JournalLine::create([
                'journal_entry_id' => $journal->id,
                'account_code' => $debitCode,
                'debit' => $this->amount,
                'credit' => 0,
                'description' => $desc,
            ]);
            JournalLine::create([
                'journal_entry_id' => $journal->id,
                'account_code' => $creditCode,
                'debit' => 0,
                'credit' => $this->amount,
                'description' => $desc,
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
            'voided_at' => 'datetime',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }
}
