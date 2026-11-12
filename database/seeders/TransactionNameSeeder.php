<?php

namespace Database\Seeders;

use App\Models\TransactionName;
use App\Models\Vihara;
use Illuminate\Database\Seeder;

/**
 * Master nama transaksi per mockup "Input Penerimaan / Pengeluaran".
 * Unique (type, name) — "Biaya Keperluan Baksos" muncul di operasional dan
 * paramita di mockup; versi paramita di-rename dengan suffix "(Paramita)".
 */
class TransactionNameSeeder extends Seeder
{
    private const NAMES = [
        'penerimaan' => [
            'dana_bhikkhu' => [
                'Dana Catupacaya',
                'Dana Makan Bhikkhu Sangha',
                'Dana Masa Vassa',
                'Dana Pabbaja Samanera',
                'Dana Ultah Bhante Subala',
                'Dana Upasampada',
            ],
            'dana_operasional' => [
                'Bunga Jasa Giro',
                'Dana Amisa Puja dan Puja dana',
                'Dana Dari Bursa',
                'Dana dari Pariyati',
                'Dana Jubah Rupang',
                'Dana Lampion dan Fangshen',
                'Dana Penjualan Jubah',
                'Dana Pepao',
            ],
            'dana_paramita' => [
                'Dana Paramita - Dhammasakacca',
                'Dana Paramita - Puja Bakti Sabtu Malam',
            ],
            'dana_perayaan' => [
                'Dana 17 an',
                'Dana Ashalha Puja',
                'Dana Athami Puja',
                'Dana Baksos Waisak',
                'Dana Hari Raya Waisak',
                'Dana Imlek',
                'Dana Kathina',
                'Dana Kathina Kids',
                'Dana Patidana Cengbeng',
                'Dana Patidana Citgwee',
                'Dana Perayaan Badasima',
            ],
        ],
        'pengeluaran' => [
            'biaya_bhikkhu' => [
                'Biaya Ultah Bhante',
                'Biaya Catupacaya',
                'Biaya Keperluan Pabajja',
                'Biaya Makan Bhikkhu',
                'Biaya Wisata Panitia Pabajja',
            ],
            'biaya_operasional' => [
                'Biaya Admin Bank',
                'Biaya ATK',
                'Biaya Fang Shen',
                'Biaya Kendaraan Vihara',
                'Biaya Keperluan SMB VVG',
                'Biaya Keperluan Baksos',
                'Biaya Maintenance Vihara',
                'Biaya Pengadaan Pindapata',
                'Biaya Takjil',
                'Gaji Karyawan Vihara',
                'Pembayaran Baju Koko',
                'Pembayaran Jubah',
                'Pembelian Lampion - Imlek',
                'Pembuatan Keperluan Link Vihara',
                'PPh',
            ],
            'biaya_paramita' => [
                'Biaya Baksos',
                'Biaya Dana Makan',
                'Biaya Distribusi Bantuan',
                'Biaya Keperluan Baksos (Paramita)',
                'Biaya Sosial Vihara',
            ],
            'biaya_perayaan' => [
                'Biaya 17an',
                'Biaya Keperluan Ashada',
                'Biaya Patidana Ceng Beng',
                'Biaya Perayaan Imlek',
                'Biaya Perayaan Kathina',
                'Biaya Perayaan Kathina Kids',
                'Biaya Perayaan Ultah Badasima',
                'Biaya Perayaan Waisak',
            ],
        ],
    ];

    public function run(): void
    {
        // Aturan yang SAMA dengan migrasi 2026_11_10_130000: nama yang
        // berakhir dengan kode vihara adalah milik vihara itu. Harus ada di
        // dua tempat karena keduanya jalur yang berbeda — migrasi mengurus
        // database yang sudah berisi data, seeder mengurus instalasi baru.
        // Pada `migrate --seed` backfill migrasi tidak menemukan apa-apa
        // (tabelnya masih kosong saat itu), jadi tanpa bagian ini instalasi
        // baru lahir tanpa pembatasan per vihara sama sekali.
        $viharaIdByCode = Vihara::query()->pluck('id', 'code');

        foreach (self::NAMES as $type => $categories) {
            foreach ($categories as $category => $names) {
                foreach ($names as $name) {
                    TransactionName::updateOrCreate(
                        ['type' => $type, 'name' => $name],
                        [
                            'category' => $category,
                            'active' => true,
                            'vihara_id' => self::viharaFor($name, $viharaIdByCode),
                        ],
                    );
                }
            }
        }
    }

    /** null = berlaku untuk semua vihara. */
    private static function viharaFor(string $name, $viharaIdByCode): ?int
    {
        foreach ($viharaIdByCode as $code => $id) {
            if (str_ends_with($name, ' ' . $code)) {
                return (int) $id;
            }
        }

        return null;
    }
}
