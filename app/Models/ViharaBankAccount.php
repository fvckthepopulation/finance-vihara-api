<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'entity_id', 'type', 'bank_name', 'account_number', 'account_name',
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

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /**
     * Vihara pengguna rekening ini (FV-36). KOSONG = seluruh vihara di
     * yayasannya boleh memakai — itu keadaan bawaan rekening yayasan.
     */
    public function viharas(): BelongsToMany
    {
        return $this->belongsToMany(Vihara::class, 'bank_account_vihara', 'bank_account_id', 'vihara_id');
    }

    /** Rekening ini boleh dipakai vihara tersebut? */
    public function bolehDipakaiVihara(?int $viharaId): bool
    {
        if ($viharaId === null) {
            return true;
        }
        $pengguna = $this->viharas()->pluck('viharas.id')->all();

        return $pengguna === [] || in_array($viharaId, $pengguna, true);
    }

    /**
     * Rekening yang boleh dipakai satu vihara: milik yayasan vihara itu, dan
     * pivot-nya kosong (semua vihara) atau memuat vihara tersebut.
     */
    public function scopeUsableByVihara($query, int $viharaId)
    {
        return $query->where(function ($q) use ($viharaId) {
            $q->whereDoesntHave('viharas')
                ->orWhereHas('viharas', fn ($w) => $w->where('viharas.id', $viharaId));
        })->whereHas('entity', fn ($q) => $q->whereHas(
            'viharas',
            fn ($w) => $w->where('viharas.id', $viharaId),
        ));
    }

    public function coaAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'coa_account_code', 'code');
    }
}
