<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FV-52 — baris rekening koran bisa ditandai sebagai setoran kas tunai.
 *
 * Penerimaan tunai masuk ke 1115 Kas Penerimaan Langsung saat uang diterima.
 * Uangnya disetor ke bank di luar aplikasi, dan selama tidak ada cara
 * mencatatnya, saldo 1115 terus bertambah walau uangnya sudah di bank — dan
 * baris setoran di rekening koran tidak pernah cocok saat rekonsiliasi.
 *
 * Keputusan alurnya: setoran dicatat SAAT REKONSILIASI, dari baris rekening
 * koran. Kolom ini menyimpan vihara asal uangnya, karena uang tunai dipegang
 * masing-masing vihara sementara rekeningnya milik yayasan.
 *
 * Jurnalnya (Dr rekening / Kr 1115) baru dibuat saat rekonsiliasi
 * diselesaikan — selama belum, penandaan masih bisa diubah atau dibatalkan
 * tanpa meninggalkan jejak di buku besar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->foreignId('setoran_vihara_id')->nullable()->constrained('viharas')->nullOnDelete();
            $table->foreignId('setoran_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('setoran_vihara_id');
            $table->dropConstrainedForeignId('setoran_journal_entry_id');
        });
    }
};
