<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['resource_type', 'name', 'steps', 'active'])]
class ApprovalWorkflow extends Model
{
    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'active' => 'boolean',
        ];
    }

    public function records(): HasMany
    {
        return $this->hasMany(ApprovalRecord::class, 'workflow_id');
    }

    public function stepCount(): int
    {
        return count($this->steps ?? []);
    }

    public function stepAt(int $order): ?array
    {
        foreach (($this->steps ?? []) as $s) {
            if ((int) $s['order'] === $order) {
                return $s;
            }
        }
        return null;
    }

    public static function forResource(string $resourceType): ?self
    {
        return static::query()
            ->where('resource_type', $resourceType)
            ->where('active', true)
            ->first();
    }
}
