<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class PeriodLockService
{
    /**
     * Throws ValidationException if the given (entity, date) is in a closed period.
     * Use at the start of any controller action that creates or modifies a dated journal/document.
     */
    public static function guard(int $entityId, Carbon|string $date, string $field = 'date'): void
    {
        if (AccountingPeriod::isLocked($entityId, $date)) {
            $d = $date instanceof Carbon ? $date : Carbon::parse($date);
            $period = $d->format('F Y');
            throw ValidationException::withMessages([
                $field => ["Periode {$period} sudah tutup buku. Jurnal koreksi harus di-post ke periode berikutnya yang masih open."],
            ]);
        }
    }
}
