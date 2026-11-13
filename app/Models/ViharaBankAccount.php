<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'vihara_id', 'type', 'bank_name', 'account_number', 'account_name',
    'coa_account_code', 'description', 'active',
])]
class ViharaBankAccount extends Model
{
    use SerializesDatesInAppTimezone;

    /** Jenis rekening (FV-30). Kas tunai tidak punya nomor rekening. */
    public const TYPE_BANK = 'bank';

    public const TYPE_CASH = 'cash';

    public const TYPES = [
        self::TYPE_BANK => 'Bank',
        self::TYPE_CASH => 'Kas Tunai',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /** Label pendek untuk mutasi/list: "BCA 6280-3488-88", atau nama kas. */
    public function label(): string
    {
        if ($this->type === self::TYPE_CASH) {
            return trim($this->bank_name ?: 'Kas Tunai');
        }

        $short = preg_replace('/^bank\s+/i', '', (string) $this->bank_name);

        return trim("{$short} {$this->account_number}");
    }

    public function vihara(): BelongsTo
    {
        return $this->belongsTo(Vihara::class);
    }

    public function coaAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'coa_account_code', 'code');
    }
}
