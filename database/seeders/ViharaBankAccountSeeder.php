<?php

namespace Database\Seeders;

use App\Models\Vihara;
use App\Models\ViharaBankAccount;
use Illuminate\Database\Seeder;

/**
 * Master rekening — milik YAYASAN sejak FV-36, bukan vihara.
 *
 * Bentuk datanya menyamai hasil migrasi di produksi: dua rekening riil VVG
 * (BCA + Mandiri) dipakai VVG saja, dan satu rekening placeholder BCA dipakai
 * bersama VBM dan VAD. Pivot `viharas` yang KOSONG berarti seluruh vihara di
 * yayasan itu boleh memakainya; di sini sengaja diisi, supaya data contoh
 * tetap mewakili rekening yang memang terbatas pemakainya.
 *
 * Catatan kenapa berkas ini sempat rusak: FV-36 memindahkan pemilik rekening
 * dari vihara ke yayasan dan MENGHAPUS kolom vihara_id, tapi seeder ini masih
 * mencarinya. Migrasinya sendiri benar — rekening produksi ikut terbawa — yang
 * tidak jalan hanya `migrate:fresh --seed` di mesin pengembang.
 */
class ViharaBankAccountSeeder extends Seeder
{
    public function run(): void
    {
        $vvg = Vihara::where('code', 'VVG')->first();
        if ($vvg) {
            $rekening = [
                ['bank_name' => 'Bank BCA',     'account_number' => '6280-3488-88',     'account_name' => $vvg->name],
                ['bank_name' => 'Bank Mandiri', 'account_number' => '1570-0122-5555-4', 'account_name' => $vvg->name],
            ];
            foreach ($rekening as $r) {
                $baris = ViharaBankAccount::updateOrCreate(
                    [
                        'entity_id' => $vvg->entity_id,
                        'bank_name' => $r['bank_name'],
                        'account_number' => $r['account_number'],
                    ],
                    $r + ['type' => 'bank', 'coa_account_code' => '1130', 'active' => true],
                );
                // syncWithoutDetaching, bukan sync: kalau admin sudah menambah
                // vihara pengguna lain, seeder tidak mencabutnya.
                $baris->viharas()->syncWithoutDetaching([$vvg->id]);
            }
        }

        // Satu placeholder untuk VBM + VAD: nomornya sama, dan sejak rekening
        // milik yayasan, nomor yang sama di satu yayasan memang SATU rekening.
        $bersama = Vihara::whereIn('code', ['VBM', 'VAD'])->get();
        if ($bersama->isNotEmpty()) {
            $baris = ViharaBankAccount::updateOrCreate(
                [
                    'entity_id' => $bersama->first()->entity_id,
                    'bank_name' => 'Bank BCA',
                    'account_number' => '0000-0000-00',
                ],
                [
                    'type' => 'bank',
                    'account_name' => 'Yayasan (placeholder)',
                    'coa_account_code' => '1130',
                    'active' => true,
                ],
            );
            $baris->viharas()->syncWithoutDetaching($bersama->pluck('id')->all());
        }
    }
}
