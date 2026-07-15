<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Settlement/payout dari KM Finance — diterima via webhook
 * POST /api/webhooks/km/settlements. Satu settlement mencairkan sejumlah
 * km_donations (gross), memotong komisi platform, dan mentransfer net ke
 * rekening bank vihara: JE Dr 1130 / Kr 1160 (net) + pembayaran komisi
 * Dr 7140 / Kr 1160 (komisi).
 */
#[Fillable([
    'ref', 'vihara_id', 'bank_account_id', 'gross', 'komisi', 'net',
    'settled_at', 'journal_entry_id', 'komisi_pembayaran_id',
])]
class KmSettlement extends Model
{
    use SerializesDatesInAppTimezone;

    protected function casts(): array
    {
        return [
            'gross' => 'decimal:2',
            'komisi' => 'decimal:2',
            'net' => 'decimal:2',
            'settled_at' => 'datetime',
        ];
    }

    public function vihara(): BelongsTo
    {
        return $this->belongsTo(Vihara::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(ViharaBankAccount::class, 'bank_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function komisiPembayaran(): BelongsTo
    {
        return $this->belongsTo(Pembayaran::class, 'komisi_pembayaran_id');
    }

    public function donations(): HasMany
    {
        return $this->hasMany(KmDonation::class, 'settlement_id');
    }
}
