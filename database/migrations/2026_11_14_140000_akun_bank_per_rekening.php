<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FV-56 — akun bank ditetapkan per rekening dan per kategori biaya.
 *
 * Semua rekening terlanjur terhubung ke 1130 Bank Donasi karena itulah nilai
 * bawaannya di migrasi, seeder, dan controller. Akibatnya SEMUA pengeluaran
 * mengurangi Bank Donasi, termasuk Biaya Operasional yang menurut acuan COA
 * ISAK 35 dibayar dari 1120 Bank Operasional. Jenis biaya yang sama bahkan
 * jatuh ke akun berbeda-beda, tergantung rekening mana yang kebetulan
 * dipilih.
 *
 * Dua perubahan:
 *
 * 1. transaction_categories.bank_account_code — akun bank yang sah untuk
 *    kategori biaya itu. Keputusan yang diambil (lihat FV-56 Perlu
 *    Konfirmasi no. 1, jawaban (a)): SELURUH pengeluaran keluar dari 1120
 *    Bank Operasional. Dana donasi di 1130 dipindahkan dulu lewat transfer
 *    antar rekening, dan pembatasan dananya dilacak lewat aset neto 3200
 *    (FV-55), bukan lewat rekening mana yang dipakai.
 *
 *    Disimpan sebagai data, bukan ditulis tetap di kode, supaya kalau nanti
 *    yayasan memutuskan Biaya Bhikkhu boleh langsung dari 1130, itu cukup
 *    diubah dari menu master.
 *
 * 2. Nilai bawaan 1130 pada kolom coa_account_code DIBUANG. Akun rekening
 *    kini wajib dipilih sadar saat mendaftarkannya.
 *
 * Pemetaan dua rekening nyata mengikuti keputusan pemilik:
 *   BCA 6280-3488-88      → 1130 Bank Donasi (rekening penerimaan)
 *   Mandiri 1570-0122-...  → 1120 Bank Operasional
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_categories', function (Blueprint $table) {
            $table->string('bank_account_code', 16)->nullable()->after('default_debit_code');
        });

        // Seluruh kategori PENGELUARAN dibayar dari Bank Operasional.
        DB::table('transaction_categories')
            ->where('type', 'pengeluaran')
            ->update(['bank_account_code' => '1120']);

        Schema::table('vihara_bank_accounts', function (Blueprint $table) {
            $table->string('coa_account_code', 16)->nullable()->default(null)->change();
        });

        // Rekening operasional yayasan sesuai keputusan pemilik. Dicocokkan
        // dari nomor rekeningnya, bukan id, supaya tetap kena walau idnya
        // berbeda antar lingkungan.
        DB::table('vihara_bank_accounts')
            ->where('account_number', 'like', '1570-0122%')
            ->update(['coa_account_code' => '1120']);
    }

    public function down(): void
    {
        Schema::table('transaction_categories', function (Blueprint $table) {
            $table->dropColumn('bank_account_code');
        });
    }
};
