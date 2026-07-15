<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 — integrasi KM Finance (KalyanaMitta). Dua tabel:
 *
 * - km_settlements: satu baris per payout/settlement dari payment gateway KM
 *   ke rekening bank vihara (gross − komisi = net). Link ke JE transfer net
 *   (Dr 1130 / Kr 1160) dan pembayaran komisi (Dr 7140 / Kr 1160).
 * - km_donations: satu baris per donasi paid yang dikirim KM via webhook.
 *   Link ke penerimaan auto-created (Dr 1160 / Kr 4xxx) dan, setelah payout,
 *   ke km_settlements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('km_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('ref', 100)->unique()->comment('ref settlement dari KM Finance');
            $table->foreignId('vihara_id')->constrained('viharas')->restrictOnDelete();
            $table->foreignId('bank_account_id')->nullable()
                ->constrained('vihara_bank_accounts')->nullOnDelete();
            $table->decimal('gross', 18, 2);
            $table->decimal('komisi', 18, 2);
            $table->decimal('net', 18, 2);
            $table->timestampTz('settled_at');
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete()
                ->comment('JE transfer net: Dr bank / Kr 1160');
            $table->foreignId('komisi_pembayaran_id')->nullable()
                ->constrained('pembayaran')->nullOnDelete()
                ->comment('pembayaran komisi_platform: Dr 7140 / Kr 1160');
            $table->timestamps();

            $table->index(['vihara_id', 'settled_at']);
        });

        Schema::create('km_donations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('km_donation_id')->unique()->comment('PK donasi di KM Finance');
            $table->string('ref', 100)->unique()->comment('ref donasi dari KM Finance');
            $table->foreignId('vihara_id')->constrained('viharas')->restrictOnDelete();
            $table->string('category', 50)->comment('key kategori dana_* (Penerimaan::CATEGORIES)');
            $table->string('name', 255)->nullable()->comment('nama transaksi seperti dikirim KM (raw)');
            $table->string('donor_name', 255)->nullable();
            $table->decimal('amount', 18, 2);
            $table->enum('status', ['paid', 'settled'])->default('paid');
            $table->timestampTz('paid_at');
            $table->timestampTz('settled_at')->nullable();
            $table->foreignId('settlement_id')->nullable()
                ->constrained('km_settlements')->nullOnDelete();
            $table->foreignId('penerimaan_id')->nullable()
                ->constrained('penerimaan')->nullOnDelete();
            $table->timestamps();

            $table->index(['vihara_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('km_donations');
        Schema::dropIfExists('km_settlements');
    }
};
