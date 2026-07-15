<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->date('bank_statement_date')->nullable()->after('description');
            $table->string('bank_statement_ref', 64)->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('reconciled_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->index('reconciled_at');
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reconciled_by_user_id');
            $table->dropColumn(['bank_statement_date', 'bank_statement_ref', 'reconciled_at']);
        });
    }
};
