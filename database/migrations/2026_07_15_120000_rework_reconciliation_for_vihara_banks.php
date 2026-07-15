<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Rekonsiliasi Bank per rekening fisik vihara.
 *
 * reconciliation_runs sekarang terikat ke `vihara_bank_accounts` (rekening
 * fisik), bukan langsung ke kode COA (dua rekening bisa share 1130).
 * `account_code` jadi kolom turunan (= coa_account_code rekening) dan nullable.
 *
 * bank_statement_lines dapat kolom polymorphic-ish matching ke dokumen
 * transaksi (penerimaan / pembayaran / petty topup) — bukan lagi ke
 * journal_lines (kolom lama dibiarkan untuk kompatibilitas, tidak dipakai).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_runs', function (Blueprint $table) {
            // Tabel kosong (belum ada run di DB) — aman menambah kolom NOT NULL.
            $table->foreignId('vihara_id')->after('code')
                ->constrained('viharas')->restrictOnDelete();
            $table->foreignId('bank_account_id')->after('vihara_id')
                ->constrained('vihara_bank_accounts')->restrictOnDelete();

            $table->string('account_code', 10)->nullable()
                ->comment('derived = coa_account_code rekening')->change();

            $table->dropUnique('recon_account_period_unique');
            $table->unique(['bank_account_id', 'period_month'], 'recon_bank_period_unique');
        });

        Schema::table('bank_statement_lines', function (Blueprint $table) {
            // Match ke dokumen transaksi: 'penerimaan'|'pembayaran'|'petty_topup'.
            $table->string('matched_type', 20)->nullable()->after('bank_ref');
            $table->unsignedBigInteger('matched_id')->nullable()->after('matched_type');
            $table->string('source', 10)->default('manual')
                ->comment('manual|import')->after('status');
            // Dedup key: "date|description|debit|credit".
            $table->string('signature', 600)->nullable()->after('source');

            $table->index(['matched_type', 'matched_id']);
            $table->index('signature');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->dropIndex(['matched_type', 'matched_id']);
            $table->dropIndex(['signature']);
            $table->dropColumn(['matched_type', 'matched_id', 'source', 'signature']);
        });

        Schema::table('reconciliation_runs', function (Blueprint $table) {
            $table->dropUnique('recon_bank_period_unique');
            $table->unique(['account_code', 'period_month'], 'recon_account_period_unique');
            $table->dropConstrainedForeignId('bank_account_id');
            $table->dropConstrainedForeignId('vihara_id');
        });
    }
};
