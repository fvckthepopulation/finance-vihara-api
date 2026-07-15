<?php

namespace Database\Seeders;

use App\Models\Vihara;
use App\Models\ViharaBankAccount;
use Illuminate\Database\Seeder;

/**
 * Master rekening bank per vihara. VVG punya dua rekening riil (BCA +
 * Mandiri); VBM/VAD masing-masing satu placeholder BCA sampai nomor
 * rekening sebenarnya tersedia.
 */
class ViharaBankAccountSeeder extends Seeder
{
    public function run(): void
    {
        $vvg = Vihara::where('code', 'VVG')->first();
        if ($vvg) {
            $accounts = [
                ['bank_name' => 'Bank BCA',     'account_number' => '6280-3488-88',    'account_name' => $vvg->name],
                ['bank_name' => 'Bank Mandiri', 'account_number' => '1570-0122-5555-4', 'account_name' => $vvg->name],
            ];
            foreach ($accounts as $a) {
                ViharaBankAccount::updateOrCreate(
                    ['vihara_id' => $vvg->id, 'bank_name' => $a['bank_name'], 'account_number' => $a['account_number']],
                    $a + ['coa_account_code' => '1130', 'active' => true],
                );
            }
        }

        foreach (['VBM', 'VAD'] as $code) {
            $vihara = Vihara::where('code', $code)->first();
            if (! $vihara) {
                continue;
            }
            ViharaBankAccount::updateOrCreate(
                ['vihara_id' => $vihara->id, 'bank_name' => 'Bank BCA', 'account_number' => '0000-0000-00'],
                [
                    'account_name' => $vihara->name,
                    'coa_account_code' => '1130',
                    'active' => true,
                ],
            );
        }
    }
}
