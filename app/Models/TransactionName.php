<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Master nama transaksi (Nama Penerimaan / Nama Pengeluaran) — lookup untuk
 * dropdown form input. `category` mengacu ke key kategori di
 * Penerimaan::CATEGORIES / Pembayaran::CATEGORIES.
 */
#[Fillable(['type', 'category', 'name', 'active'])]
class TransactionName extends Model
{
    use SerializesDatesInAppTimezone;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}
