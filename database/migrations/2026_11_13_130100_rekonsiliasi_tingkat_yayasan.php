<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FV-36 poin 6 — rekonsiliasi dilakukan per REKENING di tingkat yayasan.
 *
 * Rekening kini milik yayasan dan dipakai bersama beberapa vihara, jadi satu
 * rekonsiliasi tidak lagi bisa menunjuk satu vihara: rekening korannya memuat
 * mutasi seluruh vihara pemakai. Kolom vihara_id dibuat nullable, dan
 * rekonsiliasi baru menyimpannya kosong = tingkat yayasan.
 *
 * Baris rekonsiliasi lama dibiarkan menunjuk viharanya masing-masing; tidak
 * ada gunanya menghapus jejak itu, dan pembacaannya tetap sah.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE reconciliation_runs ALTER COLUMN vihara_id DROP NOT NULL');
    }

    public function down(): void
    {
        // Baris tingkat yayasan diberi vihara pertama pemakai rekeningnya,
        // supaya kolomnya bisa NOT NULL lagi.
        DB::statement("
            UPDATE reconciliation_runs r
            SET vihara_id = coalesce(
                (SELECT p.vihara_id FROM bank_account_vihara p WHERE p.bank_account_id = r.bank_account_id ORDER BY p.vihara_id LIMIT 1),
                (SELECT v.id FROM viharas v JOIN vihara_bank_accounts b ON b.entity_id = v.entity_id WHERE b.id = r.bank_account_id ORDER BY v.id LIMIT 1)
            )
            WHERE r.vihara_id IS NULL
        ");
        DB::statement('ALTER TABLE reconciliation_runs ALTER COLUMN vihara_id SET NOT NULL');
    }
};
