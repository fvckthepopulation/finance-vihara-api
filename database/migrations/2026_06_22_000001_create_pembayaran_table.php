<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembayaran — pengeluaran operasional vihara: draft → posted, with
 * attachment slot inline and journal entry auto-created on post.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pembayaran', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique()->comment('PAY-YYMMNNN');
            $table->date('date');

            $table->enum('method', ['transfer', 'tunai', 'auto_debit']);

            // Source of funds — bank op / kas (FK ke accounts.code)
            $table->string('source_account_code', 10);

            // Kategori pengeluaran vihara — driver default debit account
            // routing (lihat Pembayaran::CATEGORIES).
            $table->enum('category', [
                'biaya_operasional',
                'biaya_paramita',
                'biaya_bhikkhu',
                'biaya_perayaan',
                'komisi_platform',
                'utang_lain',
                'biaya_lainnya',
            ]);

            $table->foreignId('entity_id')->constrained('entities')->restrictOnDelete();

            // Payee — FK ke payees, fallback free-text untuk one-off
            $table->foreignId('payee_id')->nullable()->constrained('payees')->nullOnDelete();
            $table->string('payee_name', 255)->nullable();

            // Expense account (debit side)
            $table->string('debit_account_code', 10);

            $table->string('reference', 100)->nullable()->comment('invoice/ref eksternal');
            $table->decimal('amount', 18, 2);
            $table->text('description')->nullable();

            $table->string('attachment_path')->nullable()->comment('nota/struk/invoice scan');

            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->foreign('source_account_code')->references('code')->on('accounts')->restrictOnDelete();
            $table->foreign('debit_account_code')->references('code')->on('accounts')->restrictOnDelete();

            $table->index(['status', 'date']);
            $table->index(['entity_id', 'date']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pembayaran');
    }
};
