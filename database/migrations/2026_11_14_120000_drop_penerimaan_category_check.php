<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FV-49 poin 4 — CHECK constraint kategori penerimaan dibuang.
 *
 * `penerimaan_category_check` mengunci kolom category ke lima kode yang
 * ditulis saat tabelnya lahir. Sejak Kategori Dana pindah ke master (FV-45),
 * admin bisa menambah kategori — tapi penerimaan dengan kategori baru itu
 * ditolak database dengan SQLSTATE 23514, muncul sebagai HTTP 500 tanpa
 * penjelasan yang berguna bagi penggunanya.
 *
 * Ini kembaran persis dari 2026_11_13_110200_drop_pembayaran_category_check,
 * yang membuang jebakan yang sama di sisi pengeluaran. Sisi penerimaan
 * tertinggal karena waktu itu kategorinya memang belum bisa ditambah.
 *
 * Yang menjaga sekarang validasi di controller: kategori harus ada di master
 * DAN berstatus aktif — lebih ketat daripada CHECK ini, yang meloloskan
 * kategori yang sudah dinonaktifkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE penerimaan DROP CONSTRAINT IF EXISTS penerimaan_category_check');
    }

    public function down(): void
    {
        // Sengaja tidak dipasang lagi: daftar kode yang sah sekarang ada di
        // master, dan menuliskannya kembali di sini akan memblokir kategori
        // yang dibuat sesudah migrasi ini.
    }
};
