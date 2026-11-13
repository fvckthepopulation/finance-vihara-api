<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P-01 — unit "Umum/Pusat Yayasan".
 *
 * Yayasan punya transaksi yang bukan milik vihara mana pun: biaya pusat,
 * penerimaan atas nama yayasan, pemindahan dana antar-vihara. Selama ini
 * transaksi semacam itu hanya bisa dicatat dengan vihara kosong, dan yang
 * kosong tidak muncul di laporan per unit mana pun.
 *
 * Unit Umum dibuat sebagai baris `viharas` dengan tanda `is_umum`, satu per
 * yayasan: seluruh jalur yang sudah ada (jangkauan, jurnal, laporan, rekening
 * pengguna) langsung berlaku untuknya tanpa dimensi baru. Yang membedakan
 * hanya dua hal: dibuat otomatis, dan tidak bisa dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viharas', function (Blueprint $table) {
            $table->boolean('is_umum')->default(false)->after('entity_id');
        });

        foreach (DB::table('entities')->get(['id', 'code', 'legal_name']) as $e) {
            $sudahAda = DB::table('viharas')
                ->where('entity_id', $e->id)
                ->where('is_umum', true)
                ->exists();
            if ($sudahAda) {
                continue;
            }

            DB::table('viharas')->insert([
                'entity_id' => $e->id,
                'is_umum' => true,
                'code' => $this->kodeUnik($e->code),
                'name' => 'Umum/Pusat '.$e->legal_name,
                // Kolom bank bawaan tabel viharas masih NOT NULL (warisan
                // sebelum rekening punya tabel sendiri). Unit umum tidak
                // memegang rekening apa pun, jadi diisi kosong.
                'bank_name' => '',
                'bank_account_number' => '',
                'bank_account_holder' => '',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Kode unit umum: <KODE YAYASAN>-UMUM, dengan angka bila sudah terpakai. */
    private function kodeUnik(?string $kodeYayasan): string
    {
        $dasar = substr(($kodeYayasan ?: 'YYS').'-UMUM', 0, 16);
        $kode = $dasar;
        $n = 1;
        while (DB::table('viharas')->where('code', $kode)->exists()) {
            $kode = substr($dasar, 0, 14).$n;
            $n++;
        }

        return $kode;
    }

    public function down(): void
    {
        // Unit umum yang sudah dipakai transaksi tidak dihapus — menghapusnya
        // akan memutus transaksi itu dari unitnya.
        $dipakai = DB::table('viharas')->where('is_umum', true)
            ->where(function ($q) {
                foreach (['pembayaran', 'penerimaan', 'petty_cash_movements', 'journal_entries'] as $t) {
                    $q->orWhereExists(fn ($s) => $s->from($t)->whereColumn($t.'.vihara_id', 'viharas.id'));
                }
            })
            ->pluck('id');

        DB::table('viharas')->where('is_umum', true)->whereNotIn('id', $dipakai)->delete();

        Schema::table('viharas', function (Blueprint $table) {
            $table->dropColumn('is_umum');
        });
    }
};
