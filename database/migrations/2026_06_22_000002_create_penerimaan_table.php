<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penerimaan — penerimaan dana vihara. Mirrors pembayaran shape:
 * draft → posted, attachment slot inline, journal entry auto-created on post.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penerimaan', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique()->comment('RCV-YYMMNNN');
            $table->date('date');

            $table->enum('method', ['transfer', 'tunai', 'auto_credit']);

            // Destination of funds — bank op / kas (FK ke accounts.code)
            $table->string('destination_account_code', 10);

            // Kategori penerimaan dana vihara — driver default credit
            // (revenue/suspense) account routing (lihat Penerimaan::CATEGORIES).
            $table->enum('category', [
                'dana_operasional',
                'dana_paramita',
                'dana_bhikkhu',
                'dana_perayaan',
                'donasi_belum_teridentifikasi',
            ]);

            $table->foreignId('entity_id')->constrained('entities')->restrictOnDelete();

            // Source — FK ke payees (vendor/PSP/dst), fallback free-text
            $table->foreignId('source_payee_id')->nullable()->constrained('payees')->nullOnDelete();
            $table->string('source_name', 255)->nullable();

            // Revenue account (credit side)
            $table->string('credit_account_code', 10);

            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 18, 2);
            $table->text('description')->nullable();

            $table->string('attachment_path')->nullable();

            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->foreign('destination_account_code')->references('code')->on('accounts')->restrictOnDelete();
            $table->foreign('credit_account_code')->references('code')->on('accounts')->restrictOnDelete();

            $table->index(['status', 'date']);
            $table->index(['entity_id', 'date']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penerimaan');
    }
};
