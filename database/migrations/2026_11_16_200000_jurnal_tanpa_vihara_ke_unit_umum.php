<?php

use App\Models\Entity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FV-64 — transaksi pusat yayasan hanya di unit "Umum/Pusat Yayasan".
 *
 * Sebelumnya jurnal boleh tanpa vihara dan tampil sebagai baris
 * "Kantor Yayasan" terpisah dari unit Umum, sehingga angka pusat yayasan
 * terpecah dua. Sejak sekarang vihara wajib, dan data lama dirapikan:
 *
 *  - Jurnal tanpa vihara yang terposting dan tidak dibatalkan (saldo nyata,
 *    mis. saldo awal JE-3 YVG) DIPINDAHKAN ke unit Umum yayasannya. Nominal,
 *    akun, tanggal, dan status posting tidak berubah; saldo yayasan tetap,
 *    hanya pindah baris dari "Kantor Yayasan" ke unit Umum.
 *  - Unit Umum penerimanya diaktifkan; data rekening isian asal (nomor < 5
 *    digit) dikosongkan.
 *  - Sisanya (draft, jurnal yang sudah dibatalkan beserta jurnal
 *    pembaliknya) dihapus — saling meniadakan, tidak mengubah saldo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $tanpa = DB::table('journal_entries')->whereNull('vihara_id')->get();
            if ($tanpa->isEmpty()) {
                return;
            }

            // Pembalik (reference_type *_void) beserta jurnal aslinya dihapus
            // berpasangan. Asli dicari lewat reference_id, karena tanda batal
            // dokumen (penerimaan/pembayaran) tersimpan di dokumennya, bukan
            // di jurnal — jurnal aslinya sendiri tidak punya voided_at.
            $pembalik = $tanpa->filter(fn ($je) => $je->reference_type === 'void'
                || str_ends_with((string) $je->reference_type, '_void'));
            $dibatalkan = [];
            foreach ($pembalik as $r) {
                $dasar = $r->reference_type === 'void' ? null : substr($r->reference_type, 0, -5);
                $asli = $tanpa->first(fn ($je) => $je->id !== $r->id
                    && $je->reference_type === $dasar
                    && (int) $je->entity_id === (int) $r->entity_id
                    && (int) ($je->reference_id ?? $je->id) === (int) $r->reference_id);
                $dibatalkan[] = $r->id;
                if ($asli !== null) {
                    $dibatalkan[] = $asli->id;
                }
            }

            $pindah = $tanpa->filter(fn ($je) => $je->posted_at !== null
                && $je->voided_at === null
                && ! in_array($je->id, $dibatalkan, true));
            $hapus = $tanpa->reject(fn ($je) => $pindah->contains('id', $je->id))->pluck('id')->all();

            foreach ($pindah->groupBy('entity_id') as $entityId => $jurnal) {
                $umum = Entity::findOrFail($entityId)->pastikanUnitUmum();
                $isi = ['active' => true];
                if (strlen(preg_replace('/\D/', '', (string) $umum->bank_account_number)) < 5) {
                    $isi += ['bank_name' => '', 'bank_account_number' => '', 'bank_account_holder' => ''];
                }
                DB::table('viharas')->where('id', $umum->id)->update($isi + ['updated_at' => now()]);
                DB::table('journal_entries')
                    ->whereIn('id', $jurnal->pluck('id'))
                    ->update(['vihara_id' => $umum->id, 'updated_at' => now()]);
            }

            if ($hapus !== []) {
                DB::table('journal_entries')->whereIn('void_journal_entry_id', $hapus)->update(['void_journal_entry_id' => null]);
                DB::table('journal_lines')->whereIn('journal_entry_id', $hapus)->delete();
                DB::table('journal_entries')->whereIn('id', $hapus)->delete();
            }
        });
    }

    public function down(): void
    {
        // Perapian data satu arah.
    }
};
