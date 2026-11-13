<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FV-29 — kategori pengeluaran jadi data, bukan daftar beku.
 *
 * Kolom pembayaran.category dijaga CHECK berisi delapan kode kategori yang
 * ditulis tangan. Selama CHECK itu ada, kategori baru yang dibuat admin di
 * master akan ditolak basis data saat dipakai bertransaksi — persis yang
 * terjadi di uji e2e: master menerima kategorinya, lalu INSERT pembayaran
 * gagal dengan "violates check constraint pembayaran_category_check".
 *
 * Penjagaannya dipindah ke validasi: kategori harus ada di
 * transaction_categories DAN berstatus aktif. Itu lebih ketat daripada CHECK
 * lama (yang meloloskan kategori nonaktif), sekaligus tidak lagi memerlukan
 * migrasi tiap kali admin menambah kategori.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE pembayaran DROP CONSTRAINT IF EXISTS pembayaran_category_check');
    }

    public function down(): void
    {
        // Hanya bisa dipasang lagi kalau seluruh baris memakai kode lama.
        $asing = DB::table('pembayaran')
            ->whereNotNull('category')
            ->whereNotIn('category', [
                'biaya_operasional', 'biaya_paramita', 'biaya_bhikkhu', 'biaya_perayaan',
                'komisi_platform', 'beban_bank', 'utang_lain', 'biaya_lainnya',
            ])
            ->count();

        if ($asing > 0) {
            throw new RuntimeException(
                "Tidak bisa memasang kembali CHECK: {$asing} pembayaran memakai kategori di luar daftar lama."
            );
        }

        DB::statement("
            ALTER TABLE pembayaran ADD CONSTRAINT pembayaran_category_check
            CHECK (category IN (
                'biaya_operasional', 'biaya_paramita', 'biaya_bhikkhu', 'biaya_perayaan',
                'komisi_platform', 'beban_bank', 'utang_lain', 'biaya_lainnya'
            ))
        ");
    }
};
