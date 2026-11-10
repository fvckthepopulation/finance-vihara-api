<?php

namespace Database\Seeders;

use App\Models\Entity;
use App\Models\Vihara;
use Illuminate\Database\Seeder;

/**
 * Seed vihara-vihara di bawah Yayasan Dhammavihara Indonesia.
 * Semua vihara langsung ditandai verified (master internal, bukan
 * pendaftaran eksternal).
 */
class ViharaSeeder extends Seeder
{
    public function run(): void
    {
        // Semua vihara di seeder ini bernaung di bawah YDI.
        $entityId = Entity::where('code', 'YDI')->value('id');

        $viharas = [
            [
                'code' => 'VVG',
                'name' => 'Vihāra Vipassanā Giriratana',
                'address' => 'Bogor',
                // Rekening utama BCA; rekening alternatif Mandiri
                // 1570-0122-5555-4 (kolom tunggal — dicatat di sini saja).
                'bank_name' => 'BCA',
                'bank_account_number' => '6280-3488-88',
                'bank_account_holder' => 'Vihāra Vipassanā Giriratana',
            ],
            [
                'code' => 'VBM',
                'name' => 'Vihāra Buddhametta',
                'address' => 'Bandung',
                'bank_name' => 'BCA',
                'bank_account_number' => '0000-0000-01',
                'bank_account_holder' => 'Vihāra Buddhametta',
            ],
            [
                'code' => 'VAD',
                'name' => 'Vihāra Ananda Dharma',
                'address' => 'Jakarta',
                'bank_name' => 'BCA',
                'bank_account_number' => '0000-0000-02',
                'bank_account_holder' => 'Vihāra Ananda Dharma',
            ],
        ];

        foreach ($viharas as $v) {
            Vihara::updateOrCreate(
                ['code' => $v['code']],
                $v + [
                    'entity_id' => $entityId,
                    'active' => true,
                    'verified_at' => now(),
                ],
            );
        }
    }
}
