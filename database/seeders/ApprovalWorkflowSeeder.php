<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ApprovalWorkflowSeeder extends Seeder
{
    /**
     * Workflows are seeded per-module as the modules ship in Phase 2-6.
     * Phase 1 leaves this empty so the migration + RBAC bones can be applied
     * without owning approval flow definitions that don't exist yet.
     */
    public const WORKFLOWS = [];

    public function run(): void
    {
        // intentionally empty — see WORKFLOWS doc above
    }
}
