<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'reconciliation_run_id', 'date', 'description', 'debit', 'credit',
    'bank_ref', 'matched_type', 'matched_id', 'matched_journal_line_id',
    'status', 'source', 'signature', 'notes',
    'setoran_vihara_id', 'setoran_journal_entry_id',
])]
class BankStatementLine extends Model
{
    protected $table = 'bank_statement_lines';

    /** Dokumen sistem yang bisa di-match ke baris rekening koran. */
    public const MATCHED_TYPES = ['penerimaan', 'pembayaran', 'petty_topup'];

    /**
     * Penanda baris setoran kas tunai (FV-52) — bukan dokumen sistem, jadi
     * sengaja TIDAK masuk MATCHED_TYPES: tidak ada yang perlu dicocokkan,
     * yang ada justru jurnal baru yang dibuat saat rekonsiliasi selesai.
     */
    public const TIPE_SETORAN_TUNAI = 'setoran_tunai';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    /** Kunci dedup baris koran: tanggal|deskripsi|debit|kredit. */
    public static function makeSignature(string $date, string $description, float $debit, float $credit): string
    {
        return implode('|', [
            $date,
            mb_substr(trim(preg_replace('/\s+/', ' ', $description)), 0, 400),
            number_format($debit, 2, '.', ''),
            number_format($credit, 2, '.', ''),
        ]);
    }

    public function run(): BelongsTo { return $this->belongsTo(ReconciliationRun::class, 'reconciliation_run_id'); }
    public function matchedJournalLine(): BelongsTo { return $this->belongsTo(JournalLine::class, 'matched_journal_line_id'); }
}
