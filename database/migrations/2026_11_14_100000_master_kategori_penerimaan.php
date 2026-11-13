<?php

use App\Models\Penerimaan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FV-45 poin 5 — Kategori Dana masuk master, menyusul Kategori Biaya (FV-29).
 *
 * Kode kategori dipakai APA ADANYA dari konstanta lama
 * (Penerimaan::CATEGORIES), sama seperti yang dilakukan FV-29 untuk
 * pengeluaran: kolom penerimaan.category menyimpan kode itu, jadi seluruh
 * baris penerimaan yang sudah ada tetap terhubung ke masternya.
 *
 * `default_debit_code` sengaja DIBIARKAN KOSONG untuk empat kategori dana.
 * Pada penerimaan, kolom itu berarti "akun bawaan" di sisi kredit, dan untuk
 * keempat kategori itu akun yang benar bergantung pada kanal penerimaannya
 * (41x0 lewat KalyanaMitta, 42x0 transfer, 43x0 tunai) — satu nilai tetap
 * tidak bisa mewakilinya. Selama kosong, PenerimaanController::akunKredit()
 * jatuh ke aturan kanal × pembatasan yang sudah berjalan, jadi migrasi ini
 * tidak mengubah satu pun jurnal yang akan terbentuk. Hanya
 * 'donasi_belum_teridentifikasi' yang memang punya akun tetap (2310 suspense),
 * dan itu diisi.
 *
 * Catatan untuk yang membaca nanti: kolomnya bernama default_debit_code
 * karena lahir dari master pengeluaran. Namanya tidak diubah agar migrasi
 * FV-29 dan seluruh kueri yang sudah ada tetap sah; bacalah sebagai "akun
 * bawaan kategori", sisi mana pun yang berlaku untuk jenisnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [];
        foreach (Penerimaan::CATEGORIES as $code => $meta) {
            $ada = DB::table('transaction_categories')
                ->where('type', 'penerimaan')->where('code', $code)->exists();
            if ($ada) {
                continue;
            }
            $rows[] = [
                'type' => 'penerimaan',
                'code' => $code,
                'name' => $meta['label'],
                'description' => ($meta['restriction'] ?? null) === null
                    ? 'Tanpa klasifikasi pembatasan ISAK 35.'
                    : ($meta['restriction'] === 'dp'
                        ? 'Aset neto DENGAN pembatasan (ISAK 35).'
                        : 'Aset neto TANPA pembatasan (ISAK 35).'),
                // Lihat catatan di atas: hanya kategori suspense yang punya
                // akun tetap; yang lain mengikuti kanal penerimaannya.
                'default_debit_code' => $meta['default_credit'] ?? null,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('transaction_categories')->insert($rows);
        }
    }

    public function down(): void
    {
        DB::table('transaction_categories')
            ->where('type', 'penerimaan')
            ->whereIn('code', array_keys(Penerimaan::CATEGORIES))
            ->delete();
    }
};
