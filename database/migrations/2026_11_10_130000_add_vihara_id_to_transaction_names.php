<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nama transaksi bisa dibatasi ke satu vihara (FV-6).
 *
 * Sebelumnya master ini sepenuhnya bersama: item yang jelas-jelas milik satu
 * vihara ("Biaya Keperluan SMB VVG") muncul di dropdown semua vihara, dan
 * setiap vihara yang butuh item serupa harus dibuatkan item baru — daftarnya
 * terus memanjang untuk semua orang.
 *
 * Model yang dipakai sengaja yang paling sederhana dan masih menjawab:
 *
 *   vihara_id NULL  -> berlaku untuk SEMUA vihara (perilaku lama, mayoritas)
 *   vihara_id terisi -> hanya muncul untuk vihara itu
 *
 * Catatan yang belum terjawab di sini: tingkat YAYASAN. Pada instalasi dengan
 * lebih dari satu yayasan, item ber-vihara_id NULL tetap terlihat lintas
 * yayasan. Itu bagian dari utang "master dipakai bersama lintas yayasan" yang
 * dilaporkan terpisah, bukan permintaan issue ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_names', function (Blueprint $table) {
            $table->foreignId('vihara_id')->nullable()->after('category')
                ->constrained('viharas')->nullOnDelete();
            $table->index(['type', 'category', 'vihara_id']);
        });

        // Backfill sengaja eksplisit, bukan tebakan pintar: item yang namanya
        // BERAKHIR dengan kode vihara diikatkan ke vihara itu. Hanya itu pola
        // yang benar-benar ada di data ("… SMB VVG"); sisanya tetap NULL.
        foreach (DB::table('viharas')->get(['id', 'code']) as $vihara) {
            DB::table('transaction_names')
                ->where('name', 'like', '% ' . $vihara->code)
                ->update(['vihara_id' => $vihara->id]);
        }
    }

    public function down(): void
    {
        Schema::table('transaction_names', function (Blueprint $table) {
            $table->dropIndex(['type', 'category', 'vihara_id']);
            $table->dropConstrainedForeignId('vihara_id');
        });
    }
};
