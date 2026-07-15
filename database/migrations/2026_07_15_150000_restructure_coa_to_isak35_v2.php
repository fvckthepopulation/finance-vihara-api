<?php

use Database\Seeders\BaselineEntityCoaSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Restrukturisasi COA ke ISAK 35 v2 (file mapping COA_dan_isak35.xlsx).
 *
 * Perubahan kode pada DB lama:
 *   Kas (chain rename):  1110 Kas → 1115 Kas Penerimaan Langsung,
 *                        1120 Kas Kecil → 1110, 1140 Bank Operasional → 1120
 *   Pendapatan:          semantik berubah dari per-jenis-dana menjadi
 *                        KANAL × PEMBATASAN — tiap baris jurnal/penerimaan
 *                        dipetakan ulang berdasar penerimaan.method:
 *                        auto_credit → 41x0, transfer → 42x0, tunai → 43x0;
 *                        digit ke-3: 1 = TP (eks 4110/4120), 2 = DP (eks 4210/4220)
 *   Beban:               5110 → 6100, 5120 → 5300, 5210 → 5100, 5220 → 5200
 *
 * Terakhir meng-upsert COA final (header + akun) dari definisi seeder, jadi
 * DB lama konvergen tanpa perlu db:seed ulang. DB baru (accounts kosong saat
 * migrate) hanya menjalankan upsert. Migration satu arah — down() no-op.
 */
return new class extends Migration
{
    /** Kolom per tabel yang menyimpan kode akun COA. */
    private const CODE_COLUMNS = [
        'journal_lines' => ['account_code'],
        'penerimaan' => ['destination_account_code', 'credit_account_code'],
        'pembayaran' => ['source_account_code', 'debit_account_code'],
        'petty_cash_movements' => ['cash_account_code', 'counterpart_account_code'],
        'vihara_bank_accounts' => ['coa_account_code'],
        'reconciliation_runs' => ['account_code'],
    ];

    public function up(): void
    {
        // Marker COA lama: 1140 (Bank Operasional) tidak ada di COA v2.
        $legacy = DB::table('accounts')->where('code', '1140')->exists();

        if ($legacy) {
            // Urutan chain penting: 1115 kosong → 1110 kosong → 1120 kosong.
            $this->renameAccount('1110', '1115', 'Kas Penerimaan Langsung');
            $this->renameAccount('1120', '1110', 'Kas Kecil (Petty Cash)');
            $this->renameAccount('1140', '1120', 'Bank Operasional');

            $this->remapRevenueByChannel();

            $this->renameAccount('5110', '6100', 'Biaya Operasional Vihara');
            $this->renameAccount('5120', '5300', 'Beban Kegiatan Paramita');
            $this->renameAccount('5210', '5100', 'Beban Kegiatan Bhikkhu / Sangha');
            $this->renameAccount('5220', '5200', 'Beban Kegiatan Perayaan');
        }

        $this->extendPembayaranCategoryEnum();

        $this->upsertFinalCoa();
    }

    public function down(): void
    {
        // Data migration satu arah — pemetaan lama sudah tidak bisa
        // direkonstruksi penuh (kanal ikut method). Tidak di-reverse.
    }

    // ------------------------------------------------------------------

    /**
     * Pindahkan satu kode akun: buat akun baru (copy atribut lama), arahkan
     * semua tabel transaksi ke kode baru, hapus akun lama. FK ke
     * accounts.code aman karena akun tujuan dibuat lebih dulu.
     */
    private function renameAccount(string $old, string $new, string $newName): void
    {
        $oldRow = DB::table('accounts')->where('code', $old)->first();
        if ($oldRow === null) {
            return;
        }

        if (! DB::table('accounts')->where('code', $new)->exists()) {
            DB::table('accounts')->insert([
                'code' => $new,
                'name' => $newName,
                'type' => $oldRow->type,
                'parent_code' => null,
                'is_header' => false,
                'note' => $oldRow->note,
                'active' => (bool) $oldRow->active,
                'is_system' => (bool) ($oldRow->is_system ?? false),
                'entity_id' => $oldRow->entity_id ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (self::CODE_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::table($table)->where($column, $old)->update([$column => $new]);
            }
        }

        DB::table('accounts')->where('code', $old)->delete();
    }

    /**
     * Pendapatan lama (per jenis dana) → pendapatan v2 (kanal × pembatasan).
     * Pembatasan dari kode lama (4110/4120 TP, 4210/4220 DP); kanal dari
     * penerimaan.method baris terkait (fallback transfer untuk JE manual).
     */
    private function remapRevenueByChannel(): void
    {
        $oldCodes = ['4110', '4120', '4210', '4220'];
        $restriction = ['4110' => '1', '4120' => '1', '4210' => '2', '4220' => '2'];
        $channel = ['auto_credit' => '1', 'transfer' => '2', 'tunai' => '3'];

        // Akun target yang belum ada di COA lama (nama final via upsert).
        foreach (['4310', '4320'] as $code) {
            if (! DB::table('accounts')->where('code', $code)->exists()) {
                DB::table('accounts')->insert([
                    'code' => $code,
                    'name' => $code === '4310' ? 'Donasi Tunai — Tanpa Pembatasan' : 'Donasi Tunai — Dengan Pembatasan',
                    'type' => 'revenue',
                    'parent_code' => null,
                    'is_header' => false,
                    'active' => true,
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $target = fn (string $oldCode, ?string $method): string => '4' . ($channel[$method] ?? '2') . $restriction[$oldCode] . '0';

        // Snapshot dulu (id + kode lama + method) baru update per id, supaya
        // baris yang sudah dipindah tidak terproses dua kali (kode lama dan
        // baru saling tumpang tindih, mis. 4110 → 4210).
        $lines = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->leftJoin('penerimaan as pn', function ($join) {
                $join->on('pn.id', '=', 'je.reference_id')
                    ->whereIn('je.reference_type', ['penerimaan', 'penerimaan_reklas']);
            })
            ->whereIn('jl.account_code', $oldCodes)
            ->get(['jl.id', 'jl.account_code', 'pn.method']);

        $byTarget = [];
        foreach ($lines as $line) {
            $byTarget[$target($line->account_code, $line->method)][] = $line->id;
        }
        foreach ($byTarget as $newCode => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table('journal_lines')->whereIn('id', $chunk)->update(['account_code' => $newCode]);
            }
        }

        $rows = DB::table('penerimaan')
            ->whereIn('credit_account_code', $oldCodes)
            ->get(['id', 'credit_account_code', 'method']);

        $byTarget = [];
        foreach ($rows as $row) {
            $byTarget[$target($row->credit_account_code, $row->method)][] = $row->id;
        }
        foreach ($byTarget as $newCode => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table('penerimaan')->whereIn('id', $chunk)->update(['credit_account_code' => $newCode]);
            }
        }
    }

    /**
     * Tambah kategori 'beban_bank' ke enum pembayaran.category. Create
     * migration-nya sudah memuat nilai baru (DB fresh aman); DB lama
     * (pgsql/mysql) perlu constraint/kolomnya diperbarui di sini. SQLite
     * hanya dipakai test (selalu fresh) → skip.
     */
    private function extendPembayaranCategoryEnum(): void
    {
        $values = [
            'biaya_operasional', 'biaya_paramita', 'biaya_bhikkhu', 'biaya_perayaan',
            'komisi_platform', 'beban_bank', 'utang_lain', 'biaya_lainnya',
        ];
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $list = "'" . implode("', '", $values) . "'";
            DB::statement('ALTER TABLE pembayaran DROP CONSTRAINT IF EXISTS pembayaran_category_check');
            DB::statement("ALTER TABLE pembayaran ADD CONSTRAINT pembayaran_category_check CHECK (category::text IN ({$list}))");
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            $list = "'" . implode("', '", $values) . "'";
            DB::statement("ALTER TABLE pembayaran MODIFY category ENUM({$list}) NOT NULL");
        }
    }

    /** Upsert COA final (header + akun) dari definisi BaselineEntityCoaSeeder. */
    private function upsertFinalCoa(): void
    {
        $entityId = DB::table('entities')->orderBy('id')->value('id');

        foreach (BaselineEntityCoaSeeder::rows($entityId !== null ? (int) $entityId : null) as $row) {
            $exists = DB::table('accounts')->where('code', $row['code'])->exists();
            if ($exists) {
                DB::table('accounts')->where('code', $row['code'])
                    ->update($row + ['updated_at' => now()]);
            } else {
                DB::table('accounts')->insert($row + ['created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
};
