<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'resource_type', 'resource_id', 'workflow_id',
    'current_step', 'state',
    'submitted_at', 'submitted_by_user_id',
    'approved_at', 'approved_by_user_id',
    'posted_at', 'posted_by_user_id',
    'rejected_at', 'rejected_by_user_id', 'reject_reason',
    'history',
])]
class ApprovalRecord extends Model
{
    // MySQL disallows non-expression defaults on JSON columns, so `history`
    // is nullable at the DB level and we set the empty array at the app level.
    protected $attributes = [
        'history' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'history' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'workflow_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function appendHistory(array $entry): void
    {
        $history = $this->history ?? [];
        $history[] = $entry + ['at' => now()->toISOString()];
        $this->history = $history;
    }
}
