<?php

use App\Models\Penerimaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FV-49 — jenis pembatasan Kategori Dana pindah dari kode ke master.
 *
 * Akun pendapatan penerimaan diturunkan dari PEMBATASAN x KANAL:
 *   41x0 lewat KalyanaMitta, 42x0 transfer, 43x0 tunai;
 *   digit ke-3: 1 = Tanpa Pembatasan (TP), 2 = Dengan Pembatasan (DP).
 * Pembatasan itu selama ini ditulis tetap di Penerimaan::CATEGORIES. Sejak
 * kategori dana boleh ditambah admin lewat master (FV-45), kategori baru tidak
 * punya pembatasan sama sekali — jadi akun pendapatannya tidak bisa
 * diturunkan, dan laporan ISAK 35 tidak tahu harus menaruhnya di kolom mana.
 *
 * Kolom ini memindahkan aturan itu ke data. Nilainya:
 *   'tp'       Tanpa Pembatasan
 *   'dp'       Dengan Pembatasan
 *   'suspense' Belum teridentifikasi — memakai akun bawaan tetap (2310),
 *              bukan aturan kanal.
 *
 * Kategori PENGELUARAN dibiarkan NULL: pembatasan hanya berlaku di sisi
 * penerimaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_categories', function (Blueprint $table) {
            $table->string('restriction', 16)->nullable()->after('name');
        });

        DB::statement(
            "ALTER TABLE transaction_categories
             ADD CONSTRAINT transaction_categories_restriction_check
             CHECK (restriction IS NULL OR restriction IN ('tp', 'dp', 'suspense'))"
        );

        // Nilai awal dari konstanta, supaya kategori bawaan berperilaku persis
        // seperti sebelum migrasi ini.
        foreach (Penerimaan::CATEGORIES as $code => $meta) {
            DB::table('transaction_categories')
                ->where('type', 'penerimaan')
                ->where('code', $code)
                ->update(['restriction' => $meta['restriction'] ?? 'suspense']);
        }

        // Kategori penerimaan buatan admin yang terlanjur dibuat sebelum kolom
        // ini ada: ditandai TP, pilihan paling tidak mengejutkan (tanpa
        // pembatasan) dan tetap bisa diubah admin selama belum dipakai.
        DB::table('transaction_categories')
            ->where('type', 'penerimaan')
            ->whereNull('restriction')
            ->update(['restriction' => 'tp']);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transaction_categories DROP CONSTRAINT IF EXISTS transaction_categories_restriction_check');
        Schema::table('transaction_categories', function (Blueprint $table) {
            $table->dropColumn('restriction');
        });
    }
};
