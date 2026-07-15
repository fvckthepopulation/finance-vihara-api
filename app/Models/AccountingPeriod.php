<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'entity_id', 'year', 'month', 'status',
    'closed_at', 'closed_by_user_id', 'closing_notes',
])]
class AccountingPeriod extends Model
{
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /**
     * Check if a given date for an entity falls in a CLOSED period.
     * Returns true if locked (entry should be rejected).
     */
    public static function isLocked(int $entityId, Carbon|string $date): bool
    {
        $d = $date instanceof Carbon ? $date : Carbon::parse($date);
        return static::query()
            ->where('entity_id', $entityId)
            ->where('year', $d->year)
            ->where('month', $d->month)
            ->where('status', 'closed')
            ->exists();
    }
}
