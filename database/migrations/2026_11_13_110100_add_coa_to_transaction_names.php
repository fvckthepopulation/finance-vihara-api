<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FV-29 — master Nama Pengeluaran.
 *
 * transaction_names sudah menyimpan nama per kategori, tapi belum punya kode,
 * keterangan, maupun akun beban sendiri. Akun itulah inti permintaannya: akun
 * debit jurnal harus mengikuti Nama Pengeluaran yang dipilih, bukan satu akun
 * bawaan untuk seluruh kategori.
 *
 * Nilai awal akun tiap nama diambil dari akun bawaan kategorinya, jadi jurnal
 * yang dihasilkan sesudah migrasi ini persis sama dengan sebelumnya sampai
 * admin mengubahnya sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_names', function (Blueprint $table) {
            $table->string('code', 48)->nullable()->after('id');
            $table->string('coa_account_code', 16)->nullable()->after('name');
            $table->text('description')->nullable()->after('coa_account_code');
        });

        DB::statement("
            UPDATE transaction_names tn
            SET coa_account_code = tc.default_debit_code
            FROM transaction_categories tc
            WHERE tn.type = 'pengeluaran'
              AND tc.type = 'pengeluaran'
              AND tc.code = tn.category
              AND tn.coa_account_code IS NULL
        ");

        // Nama harus unik dalam satu kategori (dan satu vihara, karena master
        // ini boleh dipersempit per vihara sejak FV-?): unik parsial supaya
        // baris global (vihara_id NULL) dan baris per vihara tidak bentrok.
        DB::statement("
            CREATE UNIQUE INDEX transaction_names_unik_global
            ON transaction_names (type, category, lower(name))
            WHERE vihara_id IS NULL
        ");
        DB::statement("
            CREATE UNIQUE INDEX transaction_names_unik_vihara
            ON transaction_names (type, category, lower(name), vihara_id)
            WHERE vihara_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS transaction_names_unik_global');
        DB::statement('DROP INDEX IF EXISTS transaction_names_unik_vihara');
        Schema::table('transaction_names', function (Blueprint $table) {
            $table->dropColumn(['code', 'coa_account_code', 'description']);
        });
    }
};
