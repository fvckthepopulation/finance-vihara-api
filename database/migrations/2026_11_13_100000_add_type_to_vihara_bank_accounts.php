<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FV-30 — master Rekening.
 *
 * Tabel vihara_bank_accounts sudah menyimpan rekening per vihara beserta akun
 * COA-nya, tapi belum bisa membedakan rekening bank dari kas tunai, dan belum
 * ada tempat untuk keterangan. Dua kolom itu yang ditambahkan di sini; seluruh
 * baris yang sudah ada adalah rekening bank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vihara_bank_accounts', function (Blueprint $table) {
            // varchar + CHECK, bukan enum Postgres: menambah nilai baru nanti
            // tidak perlu ALTER TYPE.
            $table->string('type', 16)->default('bank')->after('vihara_id');
            $table->text('description')->nullable()->after('coa_account_code');
        });

        DB::statement("UPDATE vihara_bank_accounts SET type = 'bank' WHERE type IS NULL");

        // Kas tunai tidak punya nomor rekening, jadi kolomnya tidak boleh
        // lagi NOT NULL. Untuk jenis Bank, wajibnya ditegakkan di validasi.
        DB::statement('ALTER TABLE vihara_bank_accounts ALTER COLUMN account_number DROP NOT NULL');
        DB::statement(
            "ALTER TABLE vihara_bank_accounts
             ADD CONSTRAINT vihara_bank_accounts_type_check
             CHECK (type IN ('bank', 'cash'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE vihara_bank_accounts DROP CONSTRAINT IF EXISTS vihara_bank_accounts_type_check');
        DB::statement("UPDATE vihara_bank_accounts SET account_number = '' WHERE account_number IS NULL");
        DB::statement('ALTER TABLE vihara_bank_accounts ALTER COLUMN account_number SET NOT NULL');
        Schema::table('vihara_bank_accounts', function (Blueprint $table) {
            $table->dropColumn(['type', 'description']);
        });
    }
};
