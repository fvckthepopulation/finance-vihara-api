<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'vihara_id', 'bank_name', 'account_number', 'account_name',
    'coa_account_code', 'active',
])]
class ViharaBankAccount extends Model
{
    use SerializesDatesInAppTimezone;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /** Label pendek untuk mutasi/list: "BCA 6280-3488-88". */
    public function label(): string
    {
        $short = preg_replace('/^bank\s+/i', '', $this->bank_name);

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
