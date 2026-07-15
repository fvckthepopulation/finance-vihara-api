<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Entity;
use Illuminate\Database\Seeder;

/**
 * Baseline entity + Chart of Accounts untuk Vihara Finance.
 *
 * COA ISAK 35 v2 (nonprofit) — mengikuti file mapping "COA_dan_isak35.xlsx":
 *   1100  Kas & Setara Kas (1110 kas kecil, 1115 kas penerimaan, 1120 bank
 *         operasional, 1130 bank donasi, 1160 PG clearing KM)
 *   1300  Aset Lancar Lainnya (1310 uang muka kegiatan)
 *   2100  Liabilitas Jangka Pendek (2110 utang vendor, 2310 suspense donasi)
 *   3000  Aset Neto (3100 tanpa pembatasan, 3200 dengan pembatasan)
 *   4XXX  Pendapatan Donasi per KANAL × PEMBATASAN:
 *         41x0 via KM, 42x0 transfer bank, 43x0 tunai;
 *         digit ke-3 = 1 tanpa pembatasan (TP), 2 dengan pembatasan (DP)
 *   5XXX  Beban Program (5100 bhikkhu/sangha, 5200 perayaan, 5300 paramita*)
 *   6XXX  Beban Administrasi (6100 operasional, 6200 bank, 6300 lainnya)
 *   7XXX  Beban Fundraising (7140 komisi platform KM)
 *   9XXX  Clearing/Rekonsiliasi sementara (9170 selisih rekonsiliasi bank)
 *
 * (*) 5300 tidak ada di file mapping — ekstensi untuk kategori Biaya
 * Paramita yang sudah dipakai transaksi, konsisten dengan range Beban
 * Program 5000-5999.
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

        foreach (self::rows($entity->id) as $row) {
            Account::updateOrCreate(['code' => $row['code']], $row);
        }
    }

    /**
     * Definisi COA final — dipakai seeder ini DAN migration restrukturisasi
     * COA (agar DB lama tanpa db:seed ulang tetap konvergen).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rows(?int $entityId): array
    {
        // Tuple: [code, name, type, parent, header?, system?, entity?]
        // header = akun grup (tidak bisa diposting); system = dipakai
        // auto-posting/default aplikasi (tidak boleh dihapus).
        // Akun kas/bank di-attach ke entity YDI (dropdown "sumber dana").
        $rows = [
            // ---- 1. Aset — Kas dan Setara Kas ----
            ['1100', 'KAS DAN SETARA KAS',                        'asset',     null,   true,  true,  false],
            ['1110', 'Kas Kecil (Petty Cash)',                    'asset',     '1100', false, true,  true],
            ['1115', 'Kas Penerimaan Langsung',                   'asset',     '1100', false, true,  true],
            ['1120', 'Bank Operasional',                          'asset',     '1100', false, true,  true],
            ['1130', 'Bank Donasi',                               'asset',     '1100', false, true,  true],
            ['1160', 'Payment Gateway Clearing (KM)',             'asset',     '1100', false, true,  true],

            // ---- 1. Aset — Aset Lancar Lainnya ----
            ['1300', 'ASET LANCAR LAINNYA',                       'asset',     null,   true,  false, false],
            ['1310', 'Uang Muka Kegiatan',                        'asset',     '1300', false, false, false],

            // ---- 2. Liabilitas ----
            ['2100', 'LIABILITAS JANGKA PENDEK',                  'liability', null,   true,  false, false],
            ['2110', 'Utang Vendor Kegiatan',                     'liability', '2100', false, true,  false],
            ['2310', 'Dana Donasi Belum Teridentifikasi',         'liability', '2100', false, true,  false], // suspense

            // ---- 3. Aset Neto (ISAK 35) ----
            ['3000', 'ASET NETO',                                 'equity',    null,   true,  false, false],
            ['3100', 'Aset Neto Tanpa Pembatasan',                'equity',    '3000', false, true,  false],
            ['3200', 'Aset Neto Dengan Pembatasan',               'equity',    '3000', false, true,  false],

            // ---- 4. Pendapatan Donasi (kanal × pembatasan) ----
            ['4100', 'DONASI VIA PLATFORM (KALYANAMITTA)',        'revenue',   null,   true,  false, false],
            ['4110', 'Donasi KM — Tanpa Pembatasan',              'revenue',   '4100', false, true,  false],
            ['4120', 'Donasi KM — Dengan Pembatasan',             'revenue',   '4100', false, true,  false],
            ['4200', 'DONASI TRANSFER BANK LANGSUNG',             'revenue',   null,   true,  false, false],
            ['4210', 'Donasi Transfer — Tanpa Pembatasan',        'revenue',   '4200', false, true,  false],
            ['4220', 'Donasi Transfer — Dengan Pembatasan',       'revenue',   '4200', false, true,  false],
            ['4300', 'DONASI TUNAI LANGSUNG',                     'revenue',   null,   true,  false, false],
            ['4310', 'Donasi Tunai — Tanpa Pembatasan',           'revenue',   '4300', false, true,  false],
            ['4320', 'Donasi Tunai — Dengan Pembatasan',          'revenue',   '4300', false, true,  false],

            // ---- 5. Beban Program / Kegiatan ----
            ['5000', 'BEBAN PROGRAM / KEGIATAN',                  'expense',   null,   true,  false, false],
            ['5100', 'Beban Kegiatan Bhikkhu / Sangha',           'expense',   '5000', false, true,  false],
            ['5200', 'Beban Kegiatan Perayaan',                   'expense',   '5000', false, true,  false],
            ['5300', 'Beban Kegiatan Paramita',                   'expense',   '5000', false, true,  false],

            // ---- 6. Beban Administrasi & Umum ----
            ['6000', 'BEBAN OPERASIONAL',                         'expense',   null,   true,  false, false],
            ['6100', 'Biaya Operasional Vihara',                  'expense',   '6000', false, true,  false],
            ['6200', 'Beban Bank',                                'expense',   '6000', false, true,  false],
            ['6300', 'Beban Lainnya',                             'expense',   '6000', false, true,  false],

            // ---- 7. Beban Fundraising ----
            ['7000', 'BEBAN FUNDRAISING',                         'expense',   null,   true,  false, false],
            ['7140', 'Biaya Platform / Komisi KalyanaMitta',      'expense',   '7000', false, true,  false],

            // ---- 9. Clearing / Rekonsiliasi (sementara) ----
            ['9000', 'CLEARING / REKONSILIASI (SEMENTARA)',       'asset',     null,   true,  false, false],
            ['9170', 'Selisih Rekonsiliasi Bank',                 'asset',     '9000', false, true,  false],
        ];

        return array_map(fn (array $r) => [
            'code' => $r[0],
            'name' => $r[1],
            'type' => $r[2],
            'parent_code' => $r[3],
            'is_header' => $r[4],
            'is_system' => $r[5],
            'entity_id' => $r[6] ? $entityId : null,
            'active' => true,
        ], $rows);
    }
}
