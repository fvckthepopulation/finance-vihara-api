<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FV-51 poin 5 — merapikan deskripsi jurnal yang sudah terlanjur terbentuk.
 *
 * Baris jurnal dari pengeluaran/penerimaan dulu memakai `description` milik
 * transaksinya (field Catatan) kalau diisi, sehingga di detail JE yang
 * terbaca cuma catatan penginput — "test ubah" — bukan transaksi apa itu.
 *
 * Migrasi ini menulis ulang deskripsi JURNAL dan deskripsi BARISNYA dari data
 * transaksi sumbernya, memakai susunan yang sama dengan yang dipakai sejak
 * sekarang: nama transaksi — penerima/donatur · no. bukti — nomor & kategori.
 *
 * Akun dan nominal TIDAK disentuh sama sekali: ini perbaikan keterangan,
 * bukan perbaikan angka. Catatan penginput tetap tersimpan di barisnya
 * sendiri (pembayaran.description / penerimaan.description).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['pembayaran', 'penerimaan'] as $tabel) {
            $label = $tabel === 'pembayaran' ? 'Pembayaran' : 'Penerimaan';
            $kolomPihak = $tabel === 'pembayaran' ? 'payee_name' : 'source_name';
            $tipeKategori = $tabel === 'pembayaran' ? 'pengeluaran' : 'penerimaan';

            // Susunan dirakit di SQL supaya tidak perlu memuat ribuan baris
            // ke memori. concat_ws melewati bagian yang NULL dengan sendirinya;
            // nullif('') memastikan string kosong ikut dilewati.
            $desc = "concat_ws(' — ',
                nullif(t.name, ''),
                nullif(concat_ws(' · ', nullif(t.{$kolomPihak}, ''), nullif(t.reference, '')), ''),
                concat('{$label} ', t.code, ' — ', coalesce(c.name, t.category))
            )";

            DB::statement("
                UPDATE journal_entries je
                SET description = {$desc}
                FROM {$tabel} t
                LEFT JOIN transaction_categories c
                       ON c.code = t.category AND c.type = '{$tipeKategori}'
                WHERE je.reference_type = '{$tabel}'
                  AND je.reference_id = t.id
            ");

            DB::statement("
                UPDATE journal_lines jl
                SET description = {$desc}
                FROM journal_entries je
                JOIN {$tabel} t ON t.id = je.reference_id
                LEFT JOIN transaction_categories c
                       ON c.code = t.category AND c.type = '{$tipeKategori}'
                WHERE jl.journal_entry_id = je.id
                  AND je.reference_type = '{$tabel}'
            ");
        }
    }

    public function down(): void
    {
        // Tidak dikembalikan: deskripsi lama tidak disimpan di mana pun, dan
        // yang lama memang keliru.
    }
};
