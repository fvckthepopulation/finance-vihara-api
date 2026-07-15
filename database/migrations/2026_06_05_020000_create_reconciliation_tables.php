<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank Reconciliation — wizard-based reconciliation per bank account per month.
 * Each "run" is one reconciliation session that goes through:
 *   in_progress (importing/matching) → completed (locked)
 * Per-line matching reuses journal_lines.reconciled_at infrastructure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique()->comment('REC-YYMMNNN');
            $table->string('account_code', 10)->comment('bank account from COA 104-XX');
            $table->string('period_month', 7)->comment('YYYY-MM');
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('status', ['in_progress', 'completed', 'cancelled'])->default('in_progress');

            // Bank-side balances captured at import / matching
            $table->decimal('bank_opening_balance', 18, 2)->nullable();
            $table->decimal('bank_closing_balance', 18, 2)->nullable();
            $table->decimal('system_balance', 18, 2)->nullable()->comment('computed at complete');
            $table->decimal('adjusted_balance', 18, 2)->nullable()->comment('after corrections, at complete');

            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->useCurrent();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('account_code')->references('code')->on('accounts')->restrictOnDelete();
            $table->unique(['account_code', 'period_month'], 'recon_account_period_unique');
            $table->index(['status', 'period_month']);
        });

        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_run_id')->constrained('reconciliation_runs')->cascadeOnDelete();
            $table->date('date');
            $table->string('description', 500);
            $table->decimal('debit', 18, 2)->default(0)->comment('debit on bank statement = deposit/incoming');
            $table->decimal('credit', 18, 2)->default(0)->comment('credit on bank statement = withdrawal/outgoing');
            $table->string('bank_ref', 100)->nullable();
            $table->foreignId('matched_journal_line_id')->nullable()->constrained('journal_lines')->nullOnDelete();
            $table->enum('status', ['unmatched', 'matched', 'corrected'])->default('unmatched');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['reconciliation_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('reconciliation_runs');
    }
};
