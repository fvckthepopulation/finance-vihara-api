<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->enum('status', ['draft', 'posted'])->default('posted')->after('total_credit');
            $table->foreignId('created_by_user_id')->nullable()->after('description')->constrained('users')->nullOnDelete();
            $table->index(['reference_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
            $table->dropColumn(['status', 'created_by_user_id']);
        });
    }
};
