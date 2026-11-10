<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rapikan penamaan master Nama Pengeluaran (FV-7).
 *
 * Sebagian item ditulis dengan singkatan dan huruf kecil ("Biaya adm Bank",
 * "Biaya kep SMB VVG"), sehingga sulit dicari dan — pada collation C yang
 * dipakai Postgres produksi — terlempar ke ujung daftar.
 *
 * Nama item BUKAN kunci asing: `pembayaran.name` dan `penerimaan.name`
 * menyimpan teksnya, dan validasi `exists:transaction_names,name` mencocokkan
 * teks itu. Jadi mengganti nama di master saja akan membuat transaksi lama
 * menunjuk nama yang tidak ada lagi, dan penyuntingannya gagal validasi.
 * Karena itu ketiga tabel diubah bersama, dalam satu transaksi.
 *
 * Akronim sengaja tidak disentuh: SMB, VVG, ATK, PPh tetap apa adanya.
 */
return new class extends Migration
{
    /** nama lama => nama baru */
    private const RENAMES = [
        'Biaya adm Bank' => 'Biaya Admin Bank',
        'Biaya kep SMB VVG' => 'Biaya Keperluan SMB VVG',
        'Biaya Kep Ashada' => 'Biaya Keperluan Ashada',
    ];

    public function up(): void
    {
        $this->apply(self::RENAMES);
    }

    public function down(): void
    {
        $this->apply(array_flip(self::RENAMES));
    }

    private function apply(array $map): void
    {
        DB::transaction(function () use ($map) {
            foreach ($map as $lama => $baru) {
                DB::table('transaction_names')->where('name', $lama)->update(['name' => $baru]);

                // Transaksi yang sudah terlanjur memakai nama lama ikut
                // dipindahkan, supaya tidak ada baris yang menggantung.
                foreach (['pembayaran', 'penerimaan'] as $tabel) {
                    DB::table($tabel)->where('name', $lama)->update(['name' => $baru]);
                }
            }
        });
    }
};
