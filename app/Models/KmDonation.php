<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Donasi paid dari KM Finance (KalyanaMitta) — diterima via webhook
 * POST /api/webhooks/km/donations/paid. Setiap baris auto-membuat satu
 * penerimaan posted (Dr 1160 PG Clearing / Kr 4xxx). Status berpindah
 * paid → settled saat KM mengirim webhook settlement.
 */
#[Fillable([
    'km_donation_id', 'ref', 'vihara_id', 'category', 'name', 'donor_name',
    'amount', 'status', 'paid_at', 'settled_at', 'settlement_id', 'penerimaan_id',
])]
class KmDonation extends Model
{
    use SerializesDatesInAppTimezone;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function vihara(): BelongsTo
    {
        return $this->belongsTo(Vihara::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(KmSettlement::class, 'settlement_id');
    }

    public function penerimaan(): BelongsTo
    {
        return $this->belongsTo(Penerimaan::class, 'penerimaan_id');
    }
}
