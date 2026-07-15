<?php

namespace Database\Seeders;

use App\Models\PettyCashPic;
use App\Models\Vihara;
use Illuminate\Database\Seeder;

/** PIC pemegang kas kecil per vihara. Hanya VVG yang sudah punya PIC. */
class PettyCashPicSeeder extends Seeder
{
    public function run(): void
    {
        $vvgId = Vihara::where('code', 'VVG')->value('id');
        if (! $vvgId) {
            return;
        }

        foreach (['Lili', 'Epi'] as $name) {
            PettyCashPic::updateOrCreate(
                ['vihara_id' => $vvgId, 'name' => $name],
                ['active' => true],
            );
        }
    }
}
