<?php

namespace App\Models;

use App\Models\Concerns\HasApproval;
use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'entity_id', 'vihara_id', 'entry_date', 'reference_type', 'reference_id',
    'description', 'reference', 'total_debit', 'total_credit',
    'status', 'created_by_user_id',
    'posted_at', 'posted_by_user_id',
    'submitted_at', 'submitted_by_user_id',
    'rejected_at', 'rejected_by_user_id', 'reject_reason',
])]
class JournalEntry extends Model
{
    use HasApproval;
    use SerializesDatesInAppTimezone;

    public const APPROVAL_RESOURCE_TYPE = 'manual_journal';

    /**
     * Posts a draft manual journal (approval engine hook).
     * Just flips status to 'posted'; the lines are already created with the entry.
     */
    public function runPostAction(User $user): void
    {
        // Dua jalur sah menuju posted: lewat antrean (submitted), atau
        // langsung dari draft oleh pemegang journal.create + journal.approve —
        // sama seperti "Submit Pengeluaran" di FV-20 (FV-53).
        if (! in_array($this->status, ['draft', 'submitted'], true)) {
            throw new \RuntimeException("Journal entry #{$this->id} sudah diproses.");
        }
        $this->update([
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by_user_id' => $user->id,
        ]);
    }

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'total_debit' => 'decimal:2',
            'total_credit' => 'decimal:2',
            'posted_at' => 'datetime',
            'submitted_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /** Dimensi laporan per vihara; null = jurnal level yayasan. */
    public function vihara(): BelongsTo
    {
        return $this->belongsTo(Vihara::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }
}
