<?php

namespace App\Http\Controllers\Api;

use App\Models\Penerimaan;

/**
 * Master Penerimaan (FV-45 poin 5) — Kategori Dana sebagai induk, Nama
 * Penerimaan sebagai anaknya.
 *
 * Seluruh aturannya sama dengan Master Pengeluaran dan tinggal di kelas
 * induknya; yang berbeda hanya empat hal di bawah. Satu catatan penting soal
 * akun COA:
 *
 * Akun pendapatan penerimaan selama ini DITURUNKAN, bukan dipilih: kanal ×
 * pembatasan (41x0 lewat KalyanaMitta, 42x0 transfer, 43x0 tunai; digit ke-3
 * 1 = tanpa pembatasan, 2 = dengan pembatasan). Pembedaan itu yang dipakai
 * laporan ISAK 35. Jadi mengisi COA di sebuah Nama Penerimaan berarti
 * memakukan akunnya dan melepaskannya dari pembedaan kanal — kadang memang
 * itu yang dimau (mis. pendapatan sewa aula), tapi harus disengaja. Karena
 * itu kolomnya dibiarkan kosong untuk seluruh nama bawaan, dan selama kosong
 * perilaku jurnal tidak berubah sedikit pun.
 */
class MasterPenerimaanController extends MasterPengeluaranController
{
    protected const TIPE = 'penerimaan';

    protected const MODEL = Penerimaan::class;

    protected const JENIS_AKUN = 'revenue';

    protected const SEBUTAN = 'penerimaan';

    protected const SEBUTAN_AKUN = 'akun jenis pendapatan';

    /**
     * Nama Penerimaan TIDAK punya akun sendiri (FV-49).
     *
     * Ini mengoreksi FV-45, yang sempat menyediakan akun opsional per nama.
     * Penguji benar: akun per nama memutus aturan pembatasan x kanal, dan dua
     * penerimaan berkategori sama bisa mendarat di akun pendapatan berbeda —
     * kolom TP/DP di laporan ISAK 35 jadi tidak bisa dipercaya.
     */
    protected const AKUN_WAJIB = false;

    protected const AKUN_PER_NAMA = false;

    /** Kategori dana wajib menyatakan pembatasannya. */
    protected const PUNYA_PEMBATASAN = true;
}
