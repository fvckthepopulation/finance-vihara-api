<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_records', function (Blueprint $table) {
            $table->id();
            $table->string('resource_type', 64);
            $table->unsignedBigInteger('resource_id');
            $table->foreignId('workflow_id')->nullable()->constrained('approval_workflows')->nullOnDelete();
            $table->unsignedSmallInteger('current_step')->default(0); // 0=draft, 1+=step N, -1=posted, -2=rejected
            $table->enum('state', ['draft', 'submitted', 'approved', 'posted', 'rejected'])->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reject_reason')->nullable();
            // history JSON: [{ at, user_id, user_name, action, step, note }]
            $table->json('history')->nullable();
            $table->timestamps();

            $table->unique(['resource_type', 'resource_id']);
            $table->index(['resource_type', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_records');
    }
};
