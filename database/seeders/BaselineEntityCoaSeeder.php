<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Entity;
use Illuminate\Database\Seeder;

/**
 * Baseline entity + Chart of Accounts untuk Vihara Finance.
 *
 * COA ISAK 35 (nonprofit), kode 4 digit:
 *   1XXX  Aset (kas, kas kecil, bank, PG clearing)
 *   2XXX  Liabilitas (utang lain-lain, suspense donasi)
 *   3XXX  Aset Neto (tanpa/dengan pembatasan)
 *   4XXX  Pendapatan Dana (41xx tanpa pembatasan, 42xx dengan pembatasan)
 *   5XXX  Biaya Dana (51xx tanpa pembatasan, 52xx dengan pembatasan)
 *   7XXX  Beban Komisi Platform KM
 */
class BaselineEntityCoaSeeder extends Seeder
{
    public function run(): void
    {
        $entity = Entity::firstOrCreate(
            ['code' => 'YDI'],
            [
                'legal_name' => 'Yayasan Dhammavihara Indonesia',
                'address' => 'Jl. Dharma Utama No.1, Bogor',
                'active' => true,
            ],
        );

        // Tuple: [code, name, type, entity_id?]
        // Akun kas/bank di-attach ke entity YDI (dipakai dropdown "sumber
        // dana" per entitas); akun pendapatan/biaya shared (entity-less).
        $rows = [
            // ---- 1. Aset ----
            ['1110', 'Kas',                                        'asset',     $entity->id],
            ['1120', 'Kas Kecil',                                  'asset',     $entity->id],
            ['1130', 'Bank Donasi',                                'asset',     $entity->id],
            ['1140', 'Bank Operasional',                           'asset',     $entity->id],
            ['1160', 'Payment Gateway Clearing',                   'asset',     $entity->id],

            // ---- 2. Liabilitas ----
            ['2110', 'Utang Lain-lain',                            'liability', null],
            ['2310', 'Dana Donasi Belum Teridentifikasi',          'liability', null], // suspense

            // ---- 3. Aset Neto (ISAK 35) ----
            ['3100', 'Aset Neto Tanpa Pembatasan',                 'equity',    null],
            ['3200', 'Aset Neto Dengan Pembatasan',                'equity',    null],

            // ---- 4. Pendapatan Dana ----
            ['4110', 'Pendapatan Dana Operasional',                'revenue',   null], // tanpa pembatasan
            ['4120', 'Pendapatan Dana Paramita',                   'revenue',   null], // tanpa pembatasan
            ['4210', 'Pendapatan Dana Bhikkhu',                    'revenue',   null], // dengan pembatasan
            ['4220', 'Pendapatan Dana Perayaan',                   'revenue',   null], // dengan pembatasan

            // ---- 5. Biaya Dana ----
            ['5110', 'Biaya Operasional',                          'expense',   null],
            ['5120', 'Biaya Paramita',                             'expense',   null],
            ['5210', 'Biaya Bhikkhu',                              'expense',   null],
            ['5220', 'Biaya Perayaan',                             'expense',   null],

            // ---- 7. Beban Komisi ----
            ['7140', 'Beban Komisi Platform KM',                   'expense',   null],
        ];

        foreach ($rows as [$code, $name, $type, $entityId]) {
            Account::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'parent_code' => null,
                    'is_header' => false,
                    'entity_id' => $entityId,
                    'active' => true,
                ],
            );
        }
    }
}
