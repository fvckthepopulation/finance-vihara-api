<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Target anggaran per vihara per tahun. name null = target level kategori;
 * name terisi = target per Nama Pengeluaran (baris detail).
 */
#[Fillable([
    'vihara_id', 'year', 'type', 'category', 'name', 'amount',
])]
class Budget extends Model
{
    use SerializesDatesInAppTimezone;

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    public function vihara(): BelongsTo
    {
        return $this->belongsTo(Vihara::class);
    }
}
