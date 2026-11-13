<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FV-36 — rekening bank milik YAYASAN, bukan vihara.
 *
 * Rekening dibuka atas nama yayasan dan dipakai bersama vihara-vihara di
 * bawahnya. Selama pemiliknya vihara, satu rekening fisik harus didaftarkan
 * berulang per vihara, saldonya terpecah, dan rekening koran tidak pernah cocok
 * saat rekonsiliasi.
 *
 * Sesudah migrasi ini:
 *   - vihara_bank_accounts.entity_id = pemiliknya (wajib);
 *   - vihara_bank_accounts.vihara_id dihapus;
 *   - tabel pivot bank_account_vihara menyimpan "vihara pengguna". KOSONG
 *     berarti seluruh vihara di yayasan itu boleh memakainya (bawaan).
 *
 * Penggabungan: rekening dengan (yayasan, nama bank, nomor) yang sama digabung
 * jadi satu baris; seluruh transaksi yang menunjuk baris duplikat dialihkan ke
 * baris yang dipertahankan, jadi tidak ada transaksi yang kehilangan rekening.
 *
 * Soal pivot hasil penggabungan: kalau gabungan itu sudah mencakup SELURUH
 * vihara yayasannya, pivotnya dikosongkan (= semua vihara). Kalau hanya
 * sebagian, pivotnya diisi vihara-vihara itu saja — sengaja konservatif, supaya
 * migrasi tidak diam-diam melebarkan akses rekening yang sebelumnya terbatas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_account_vihara', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('vihara_bank_accounts')->cascadeOnDelete();
            $table->foreignId('vihara_id')->constrained('viharas')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['bank_account_id', 'vihara_id']);
        });

        Schema::table('vihara_bank_accounts', function (Blueprint $table) {
            $table->foreignId('entity_id')->nullable()->after('id')->constrained('entities');
        });

        // 1. Pemilik: yayasan dari vihara yang selama ini memegang rekeningnya.
        DB::statement('
            UPDATE vihara_bank_accounts b
            SET entity_id = v.entity_id
            FROM viharas v
            WHERE v.id = b.vihara_id AND b.entity_id IS NULL
        ');

        // 2. Vihara pengguna: mula-mula persis seperti sebelumnya (satu vihara
        //    per baris). Penggabungan di bawah yang menyatukannya.
        DB::statement('
            INSERT INTO bank_account_vihara (bank_account_id, vihara_id, created_at, updated_at)
            SELECT id, vihara_id, now(), now()
            FROM vihara_bank_accounts
            WHERE vihara_id IS NOT NULL
        ');

        $this->gabungkanDuplikat();

        // 3. Pivot yang sudah mencakup seluruh vihara yayasannya dikosongkan,
        //    karena itu memang arti "semua vihara".
        DB::statement('
            DELETE FROM bank_account_vihara p
            USING vihara_bank_accounts b
            WHERE p.bank_account_id = b.id
              AND (
                SELECT count(*) FROM bank_account_vihara x WHERE x.bank_account_id = b.id
              ) = (
                SELECT count(*) FROM viharas v WHERE v.entity_id = b.entity_id
              )
        ');

        Schema::table('vihara_bank_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vihara_id');
        });

        DB::statement('ALTER TABLE vihara_bank_accounts ALTER COLUMN entity_id SET NOT NULL');
    }

    /**
     * Rekening fisik yang sama (yayasan + nama bank + nomor) disatukan, dan
     * seluruh transaksi yang menunjuk duplikatnya dialihkan.
     */
    private function gabungkanDuplikat(): void
    {
        $grup = DB::select("
            SELECT entity_id, bank_name, coalesce(account_number, '') AS nomor,
                   min(id) AS induk, array_agg(id) AS anggota
            FROM vihara_bank_accounts
            GROUP BY entity_id, bank_name, coalesce(account_number, '')
            HAVING count(*) > 1
        ");

        foreach ($grup as $g) {
            $anggota = array_map('intval', explode(',', trim($g->anggota, '{}')));
            $duplikat = array_values(array_filter($anggota, fn ($id) => $id !== (int) $g->induk));
            if ($duplikat === []) {
                continue;
            }

            foreach ([
                ['pembayaran', 'bank_account_id'],
                ['penerimaan', 'bank_account_id'],
                ['petty_cash_movements', 'source_bank_account_id'],
                ['reconciliation_runs', 'bank_account_id'],
                ['km_settlements', 'bank_account_id'],
            ] as [$tabel, $kolom]) {
                DB::table($tabel)->whereIn($kolom, $duplikat)->update([$kolom => $g->induk]);
            }

            // Vihara pengguna duplikat ikut pindah ke baris induk.
            DB::statement(
                'INSERT INTO bank_account_vihara (bank_account_id, vihara_id, created_at, updated_at)
                 SELECT ?, vihara_id, now(), now() FROM bank_account_vihara
                 WHERE bank_account_id = ANY(?)
                 ON CONFLICT (bank_account_id, vihara_id) DO NOTHING',
                [$g->induk, '{'.implode(',', $duplikat).'}']
            );

            DB::table('bank_account_vihara')->whereIn('bank_account_id', $duplikat)->delete();
            DB::table('vihara_bank_accounts')->whereIn('id', $duplikat)->delete();
        }
    }

    public function down(): void
    {
        Schema::table('vihara_bank_accounts', function (Blueprint $table) {
            $table->foreignId('vihara_id')->nullable()->after('entity_id')->constrained('viharas');
        });

        // Dikembalikan ke salah satu vihara penggunanya; kalau pivotnya kosong,
        // dipakai vihara pertama di yayasan itu. Penggabungan TIDAK bisa
        // dibatalkan — baris duplikat yang dihapus tidak dilahirkan kembali.
        DB::statement('
            UPDATE vihara_bank_accounts b
            SET vihara_id = coalesce(
                (SELECT p.vihara_id FROM bank_account_vihara p WHERE p.bank_account_id = b.id ORDER BY p.vihara_id LIMIT 1),
                (SELECT v.id FROM viharas v WHERE v.entity_id = b.entity_id ORDER BY v.id LIMIT 1)
            )
        ');

        Schema::dropIfExists('bank_account_vihara');
        Schema::table('vihara_bank_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entity_id');
        });
    }
};
