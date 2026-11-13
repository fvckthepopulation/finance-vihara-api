<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FV-31 — master Penerima.
 *
 * Tabel payees sudah ada (warisan bagan master lama) tapi jenisnya dijaga
 * CHECK berisi 'supplier'/'provider' — istilah yang tidak dipakai di layar
 * mana pun di aplikasi ini. Issue meminta Vendor / Perorangan / Internal.
 *
 * Tabelnya masih kosong, jadi daftar jenisnya diganti, bukan ditambah.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE payees DROP CONSTRAINT IF EXISTS payees_type_check');
        DB::statement("UPDATE payees SET type = 'vendor' WHERE type IN ('supplier', 'provider')");
        DB::statement("
            ALTER TABLE payees ADD CONSTRAINT payees_type_check
            CHECK (type IN ('vendor', 'perorangan', 'internal'))
        ");

        // Nama penerima tidak boleh kembar dalam satu yayasan, tanpa
        // membedakan huruf besar/kecil — itu inti keluhannya ("Fotocopy",
        // "fotocopy", "Toko Fotocopy" jadi tiga baris berbeda). Unik parsial,
        // karena entity_id boleh NULL (penerima lintas yayasan).
        DB::statement('
            CREATE UNIQUE INDEX payees_unik_per_yayasan
            ON payees (entity_id, lower(name))
            WHERE entity_id IS NOT NULL
        ');
        DB::statement('
            CREATE UNIQUE INDEX payees_unik_global
            ON payees (lower(name))
            WHERE entity_id IS NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payees_unik_per_yayasan');
        DB::statement('DROP INDEX IF EXISTS payees_unik_global');
        DB::statement('ALTER TABLE payees DROP CONSTRAINT IF EXISTS payees_type_check');
        DB::statement("UPDATE payees SET type = 'supplier' WHERE type NOT IN ('supplier', 'provider')");
        DB::statement("
            ALTER TABLE payees ADD CONSTRAINT payees_type_check
            CHECK (type IN ('supplier', 'provider'))
        ");
    }
};
