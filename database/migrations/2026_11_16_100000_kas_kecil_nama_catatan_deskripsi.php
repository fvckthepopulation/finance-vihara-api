<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kas Kecil memakai master yang sama dengan Input Pengeluaran (FV-82),
 * punya Catatan sendiri (FV-85), dan deskripsi jurnalnya menyebut keperluan
 * (FV-87).
 *
 * 1. Kolom baru: `name` (Nama Pengeluaran dari master) dan `notes`
 *    (Catatan, terpisah dari keterangan keperluan).
 * 2. Kategori lama kas kecil (operasional, bhikkhu, …) dipetakan ke kode
 *    master Kategori Biaya. Akunnya sama persis (6100, 5100, 5200, 5300,
 *    6300), jadi jurnal yang sudah ada tidak berubah angkanya.
 * 3. Deskripsi jurnal kas kecil yang sudah terbentuk ditulis ulang dengan
 *    susunan baru. Akun dan nominal TIDAK disentuh — hanya keterangannya.
 */
return new class extends Migration
{
    private const PETA_KATEGORI = [
        'operasional' => 'biaya_operasional',
        'paramita' => 'biaya_paramita',
        'bhikkhu' => 'biaya_bhikkhu',
        'perayaan' => 'biaya_perayaan',
        'lainnya' => 'biaya_lainnya',
    ];

    public function up(): void
    {
        Schema::table('petty_cash_movements', function (Blueprint $table) {
            $table->string('name')->nullable()->after('category');
            $table->string('notes', 500)->nullable()->after('description');
        });

        foreach (self::PETA_KATEGORI as $lama => $baru) {
            DB::table('petty_cash_movements')->where('category', $lama)->update(['category' => $baru]);
        }

        // Pengisian: field Catatan dulu tersimpan di `description`. Pindahkan
        // ke `notes` supaya artinya sama dengan Catatan di penggunaan.
        DB::table('petty_cash_movements')
            ->where('subtype', 'topup')
            ->whereNotNull('description')
            ->update(['notes' => DB::raw('description'), 'description' => null]);

        $mutasi = \App\Models\PettyCashMovement::query()
            ->with(['pic:id,name', 'sourceBankAccount'])
            ->whereNotNull('journal_entry_id')
            ->get();

        foreach ($mutasi as $m) {
            $desc = $m->deskripsiJurnal();
            DB::table('journal_entries')->where('id', $m->journal_entry_id)->update(['description' => $desc]);
            DB::table('journal_lines')->where('journal_entry_id', $m->journal_entry_id)->update(['description' => $desc]);

            // Jurnal pembaliknya (bila dibatalkan) ikut menyebut keperluannya.
            $pembalik = DB::table('journal_entries')
                ->where('reference_type', 'petty_cash_void')
                ->where('reference_id', $m->id)
                ->get(['id', 'description']);
            foreach ($pembalik as $p) {
                $alasan = $m->void_reason ?? (str_contains((string) $p->description, ' — ')
                    ? substr((string) $p->description, strpos((string) $p->description, ' — ') + strlen(' — '))
                    : '');
                $descBalik = \App\Models\PettyCashMovement::deskripsiPembalik($desc, (string) $alasan);
                DB::table('journal_entries')->where('id', $p->id)->update(['description' => $descBalik]);
                DB::table('journal_lines')->where('journal_entry_id', $p->id)->update(['description' => $descBalik]);
            }
        }
    }

    public function down(): void
    {
        DB::table('petty_cash_movements')
            ->where('subtype', 'topup')
            ->whereNotNull('notes')
            ->whereNull('description')
            ->update(['description' => DB::raw('notes')]);

        foreach (self::PETA_KATEGORI as $lama => $baru) {
            DB::table('petty_cash_movements')->where('category', $baru)->update(['category' => $lama]);
        }

        Schema::table('petty_cash_movements', function (Blueprint $table) {
            $table->dropColumn(['name', 'notes']);
        });
    }
};
