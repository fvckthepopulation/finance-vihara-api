<?php

namespace App\Models\Concerns;

use App\Models\ApprovalRecord;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Trait for resources that go through the approval engine.
 *
 * Resource model must also:
 *  - define const APPROVAL_RESOURCE_TYPE = 'ap_invoice' (or similar)
 *  - implement public function runPostAction(\App\Models\User $user): void
 */
trait HasApproval
{
    public function approvalRecord(): MorphOne
    {
        return $this->morphOne(ApprovalRecord::class, null, 'resource_type', 'resource_id')
            ->where('resource_type', static::APPROVAL_RESOURCE_TYPE);
    }

    public function approvalResourceType(): string
    {
        return static::APPROVAL_RESOURCE_TYPE;
    }
}
