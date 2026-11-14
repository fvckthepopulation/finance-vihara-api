<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Entity;
use App\Models\Vihara;
use App\Services\ViharaScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Laporan ISAK 35 (nonprofit) — semua angka dihitung dari journal_entries +
 * journal_lines yang sudah posted (posted_at not null), difilter dimensi
 * je.vihara_id. vihara_id null = konsolidasi seluruh yayasan, termasuk
 * jurnal level yayasan (vihara_id null).
 *
 * Empat seksi: posisi keuangan, penghasilan komprehensif, perubahan aset
 * neto, arus kas (metode langsung). Semua nominal string 2 desimal.
 */
class LaporanController extends Controller
{
    /** Kas & setara kas (COA v2: kas kecil, kas penerimaan, bank ops, bank donasi). */
    /**
     * Akun kas & setara BAWAAN. Dipakai hanya sebagai cadangan kalau bagan
     * akun kosong; yang sebenarnya dibaca kodeKas(), supaya akun baru dari
     * menu Chart of Accounts ikut masuk laporan (FV-57).
     */
    private const KAS = ['1110', '1115', '1120', '1130'];

    /**
     * Dana di payment gateway — 11xx juga, tapi dilaporkan sebagai barisnya
     * sendiri di Posisi Keuangan, jadi tidak ikut dihitung sebagai kas
     * supaya tidak dobel.
     */
    private const AKUN_PG_CLEARING = '1160';

    /**
     * Pendapatan donasi = kanal × pembatasan (41x0 KM, 42x0 transfer,
     * 43x0 tunai); digit ke-3 = 1 tanpa pembatasan (TP), 2 dengan (DP).
     */
    private const TP_REV = ['4110', '4210', '4310'];
    private const DP_REV = ['4120', '4220', '4320'];

    /**
     * Kelompok beban (fungsional) by prefix COA: 5xxx program (bhikkhu,
     * perayaan, paramita), 6xxx administrasi & umum, 7xxx fundraising.
     */
    private const PREFIX_PROGRAM = '5';
    private const PREFIX_ADMIN = '6';
    private const PREFIX_FUNDRAISING = '7';

    /** Aset lancar lainnya (13xx: uang muka kegiatan). */
    private const PREFIX_ASET_LAIN = '13';

    /** Akun clearing/rekonsiliasi sementara (9xxx) — target saldo Rp0. */
    private const PREFIX_REKON = '9';

    /** Cadangan; yang sebenarnya dibaca kodeLiabilitas() dari COA (FV-57). */
    private const LIABILITAS = ['2110', '2310'];

    private const ARUS_KAS_LABELS = [
        'penerimaan_transfer' => 'Penerimaan donasi — transfer bank',
        'penerimaan_tunai' => 'Penerimaan donasi — tunai',
        'penerimaan_km_settled' => 'Penerimaan via platform KM (settled)',
        'pembayaran_program' => 'Pembayaran program / kegiatan',
        'pembayaran_admin' => 'Pembayaran administrasi & umum',
        'petty_usage' => 'Pemakaian kas kecil',
        'lainnya' => 'Arus kas lainnya',
    ];

    public function isak35(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['sometimes', 'in:ytd,bulan'],
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required_if:mode,bulan', 'integer', 'min:1', 'max:12'],
            'vihara_id' => ['sometimes', 'integer', 'exists:viharas,id'],
        ]);

        // Batas akses user + filter ?vihara_id=. Baris level yayasan hanya
        // ikut bila user memang berhak DAN tidak sedang memfokuskan satu
        // vihara tertentu.
        $viharaIds = ViharaScopeService::readFilterIds($request);
        $includeYayasan = ! $request->filled('vihara_id') && $request->user()->canSeeYayasanLevel();

        [$mode, $year, $month, $startD, $endD, $beforeD] = $this->resolvePeriod($data);

        $report = $this->buildReport(self::filterViharas($viharaIds, $includeYayasan), $startD, $endD, $beforeD);

        return response()->json([
            'period' => [
                'mode' => $mode,
                'year' => $year,
                'month' => $month,
                'start' => $startD,
                'end' => $endD,
            ],
            'posisi' => $report['posisi'],
            'penghasilan' => $report['penghasilan'],
            'perubahan' => $report['perubahan'],
            'arus_kas' => $report['arus_kas'],
        ]);
    }

    /**
     * Konsolidasi yayasan: satu report ISAK 35 ringkas per vihara + total
     * konsolidasi. Hanya untuk user global (users.vihara_id null) — user
     * dengan vihara tetap tidak boleh melihat angka vihara lain.
     */
    public function konsolidasi(Request $request): JsonResponse
    {
        // Hanya pemegang akses tingkat yayasan (global / entity). User yang
        // di-scope ke vihara tidak boleh melihat angka vihara lain.
        if (! $request->user()->canSeeYayasanLevel()) {
            return response()->json([
                'message' => 'Konsolidasi hanya untuk akses tingkat yayasan.',
            ], 403);
        }

        $data = $request->validate([
            'mode' => ['sometimes', 'in:ytd,bulan'],
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required_if:mode,bulan', 'integer', 'min:1', 'max:12'],
        ]);

        [$mode, $year, $month, $startD, $endD, $beforeD] = $this->resolvePeriod($data);

        $perVihara = [];
        $totals = null;

        // Identitas yayasan untuk header laporan. User scope entity pakai
        // yayasannya sendiri; scope global pakai yayasan pertama.
        $yayasan = $request->user()->entity_id !== null
            ? Entity::find($request->user()->entity_id)
            : Entity::query()->orderBy('id')->first();

        // Hanya vihara yang boleh dilihat user (scope entity = sebatas
        // yayasannya; scope global = semua).
        $viharas = ViharaScopeService::visibleViharasQuery($request->user())
            ->orderBy('code')
            ->get();

        foreach ($viharas as $vihara) {
            $raw = $this->buildReport(self::filterViharas([(int) $vihara->id]), $startD, $endD, $beforeD)['raw'];
            $perVihara[] = $this->konsolidasiEntry([
                'id' => (int) $vihara->id,
                'code' => $vihara->code,
                'name' => $vihara->name,
                // Tabel viharas hanya punya `address` (alamat lengkap),
                // tidak ada kolom kota tersendiri → null.
                'kota' => null,
            ], $raw);
            $totals = $this->accumulateRaw($totals, $raw);
        }

        // Jurnal level yayasan (je.vihara_id NULL) — tampil sebagai entitas
        // "Kantor Yayasan" hanya bila ada nilai ≠ 0.
        $yysRaw = $this->buildReport(self::filterYayasanOnly(), $startD, $endD, $beforeD)['raw'];
        if ($this->rawHasValue($yysRaw)) {
            $perVihara[] = $this->konsolidasiEntry([
                'id' => null,
                'code' => 'YYS',
                'name' => 'Kantor Yayasan',
                'kota' => null,
            ], $yysRaw);
            $totals = $this->accumulateRaw($totals, $yysRaw);
        }

        $konsolidasi = $this->konsolidasiEntry(null, $totals);

        return response()->json([
            'period' => [
                'mode' => $mode,
                'year' => $year,
                'month' => $month,
                'start' => $startD,
                'end' => $endD,
            ],
            'yayasan' => [
                // Multi-yayasan: ambil dari entity user, bukan hardcode.
                'nama' => $yayasan?->legal_name ?? 'Yayasan',
                'singkatan' => $yayasan?->code ?? '-',
            ],
            'per_vihara' => $perVihara,
            'konsolidasi' => $konsolidasi,
        ]);
    }

    /**
     * Parse mode/year/month tervalidasi → [mode, year, month, start, end,
     * before] (tanggal string; before = sehari sebelum start, batas saldo
     * kumulatif awal).
     *
     * @return array{0: string, 1: int, 2: ?int, 3: string, 4: string, 5: string}
     */
    private function resolvePeriod(array $data): array
    {
        $mode = $data['mode'] ?? 'ytd';
        $year = (int) ($data['year'] ?? now()->year);
        $month = $mode === 'bulan' ? (int) $data['month'] : null;

        $start = CarbonImmutable::create($year, $month ?? 1, 1);
        $end = $mode === 'bulan' ? $start->endOfMonth() : $start->endOfYear();

        return [$mode, $year, $month, $start->toDateString(), $end->toDateString(), $start->subDay()->toDateString()];
    }

    /**
     * Hitung keempat seksi ISAK 35 untuk satu filter vihara:
     * int = satu vihara, null = seluruh yayasan (semua vihara + level
     * yayasan), atau hanya jurnal level yayasan (filterYayasanOnly()).
     *
     * Return: posisi/penghasilan/perubahan/arus_kas (payload isak35) + raw
     * (angka float sebelum format — dipakai konsolidasi agar penjumlahan
     * lintas vihara eksak, bukan parse ulang string).
     *
     * @return array{posisi: array, penghasilan: array, perubahan: array, arus_kas: array, raw: array<string, float>}
     */
    private function buildReport(array $viharaFilter, string $startD, string $endD, string $beforeD): array
    {
        // Saldo net (debit − kredit) per akun: kumulatif SEBELUM periode +
        // mutasi DALAM periode. Kumulatif s/d akhir = penjumlahan keduanya.
        $before = $this->netByAccount($viharaFilter, null, $beforeD);
        $period = $this->netByAccount($viharaFilter, $startD, $endD);
        $cum = $before;
        foreach ($period as $code => $net) {
            $cum[$code] = ($cum[$code] ?? 0.0) + $net;
        }

        $names = Account::query()->pluck('name', 'code');

        // ---------- Laporan Posisi Keuangan (per akhir periode) ----------
        $kasRows = [];
        $kasTotal = 0.0;
        foreach ($this->kodeKas() as $code) {
            $bal = $cum[$code] ?? 0.0; // aset: debit-normal
            $kasTotal += $bal;
            $kasRows[] = ['code' => $code, 'name' => $names[$code] ?? $code, 'balance' => $this->fmt($bal)];
        }

        $pgClearing = $cum[self::AKUN_PG_CLEARING] ?? 0.0;

        // Aset lancar lainnya (13xx uang muka) + saldo clearing/rekonsiliasi
        // sementara (9xxx — seharusnya Rp0 setelah rekonsiliasi selesai).
        $asetLainRows = [];
        $asetLainTotal = 0.0;
        foreach ($this->codesWithPrefix($cum, self::PREFIX_ASET_LAIN) as $code) {
            $bal = $cum[$code];
            $asetLainTotal += $bal;
            $asetLainRows[] = ['code' => $code, 'name' => $names[$code] ?? $code, 'balance' => $this->fmt($bal)];
        }
        $rekonSementara = $this->sumPrefix($cum, self::PREFIX_REKON);

        $totalAset = $kasTotal + $pgClearing + $asetLainTotal + $rekonSementara;

        $liabRows = [];
        $liabTotal = 0.0;
        foreach ($this->kodeLiabilitas() as $code) {
            $bal = -($cum[$code] ?? 0.0); // liabilitas: credit-normal
            $liabTotal += $bal;
            $liabRows[] = ['code' => $code, 'name' => $names[$code] ?? $code, 'balance' => $this->fmt($bal)];
        }

        // ---------- Pendapatan & beban (klasifikasi TP/DP) ----------
        [$revTpBefore, $revDpBefore] = $this->revenueSplit($before);
        [$revTpPeriod, $revDpPeriod] = $this->revenueSplit($period);

        $bebanBefore = $this->bebanTotal($before);
        $bebanProgram = $this->sumPrefix($period, self::PREFIX_PROGRAM);
        $bebanAdmin = $this->sumPrefix($period, self::PREFIX_ADMIN);
        $bebanFundraising = $this->sumPrefix($period, self::PREFIX_FUNDRAISING);
        $bebanTotal = $bebanProgram + $bebanAdmin + $bebanFundraising;

        // Saldo akun aset neto itu sendiri (3100/3200). Jurnal saldo awal
        // "Dr 1120 / Kr 3100" menambah aset TANPA menambah pendapatan, jadi
        // kalau akun ini tidak dibaca, asetnya bertambah sementara aset
        // netonya tidak — dan Laporan Posisi Keuangan tidak pernah seimbang
        // (FV-54). Yang SEBELUM periode jadi saldo awal; yang DI DALAM
        // periode jadi kenaikan periode berjalan.
        [$ekuitasAwalTp, $ekuitasAwalDp] = $this->asetNetoSplit($before);
        [$ekuitasPeriodTp, $ekuitasPeriodDp] = $this->asetNetoSplit($period);

        // Aset neto: seluruh beban dialokasikan ke TP (praktik mockup —
        // pengeluaran dana terikat dicatat sebagai beban TP).
        $awalTp = $revTpBefore - $bebanBefore + $ekuitasAwalTp;
        $awalDp = $revDpBefore + $ekuitasAwalDp;
        $kenaikanTp = $revTpPeriod - $bebanTotal + $ekuitasPeriodTp;
        $kenaikanDp = $revDpPeriod + $ekuitasPeriodDp;
        $akhirTp = $awalTp + $kenaikanTp;
        $akhirDp = $awalDp + $kenaikanDp;

        // Selisih jujur: aset − (liabilitas + aset neto TP/DP). Bisa ≠ 0
        // mis. saat reklas suspense lintas kelas atau manual JE menyentuh
        // akun di luar kelompok laporan ini.
        $selisih = $totalAset - ($liabTotal + $akhirTp + $akhirDp);

        $posisi = [
            'as_of' => $endD,
            'kas_setara' => ['rows' => $kasRows, 'total' => $this->fmt($kasTotal)],
            'pg_clearing' => $this->fmt($pgClearing),
            'aset_lainnya' => ['rows' => $asetLainRows, 'total' => $this->fmt($asetLainTotal)],
            'rekonsiliasi_sementara' => $this->fmt($rekonSementara),
            'total_aset' => $this->fmt($totalAset),
            'liabilitas' => ['rows' => $liabRows, 'total' => $this->fmt($liabTotal)],
            'aset_neto' => [
                'tp' => $this->fmt($akhirTp),
                'dp' => $this->fmt($akhirDp),
                'total' => $this->fmt($totalAset - $liabTotal),
            ],
            'balance_ok' => abs($selisih) < 0.005,
            'selisih' => $this->fmt($selisih),
        ];

        // Pelepasan pembatasan periode berjalan (FV-55). Angkanya sudah ikut
        // terhitung di kenaikan TP/DP lewat akun 3100/3200; yang diambil di
        // sini nilainya saja, untuk DITAMPILKAN sebagai barisnya sendiri —
        // template Laporan Penghasilan Komprehensif memang punya baris itu.
        $pelepasan = $this->pelepasanPembatasan($viharaFilter, $startD, $endD);

        // ---------- Laporan Penghasilan Komprehensif (periode) ----------
        $pendapatanRows = [];
        $revCodes = array_filter(array_keys($period), fn ($c) => str_starts_with((string) $c, '4'));
        sort($revCodes);
        foreach ($revCodes as $code) {
            $amount = -($period[$code] ?? 0.0); // revenue: credit-normal
            if (abs($amount) < 0.005) {
                continue;
            }
            $isDp = $this->isDpRevenue((string) $code);
            $pendapatanRows[] = [
                'code' => (string) $code,
                'label' => $names[$code] ?? (string) $code,
                'tp' => $isDp ? null : $this->fmt($amount),
                'dp' => $isDp ? $this->fmt($amount) : null,
                'total' => $this->fmt($amount),
            ];
        }

        $bebanRows = [
            ['group' => 'program', 'label' => 'Beban Program (Bhikkhu, Perayaan, Paramita)', 'total' => $this->fmt($bebanProgram)],
            ['group' => 'admin', 'label' => 'Beban Administrasi & Umum (Operasional, Bank, Lainnya)', 'total' => $this->fmt($bebanAdmin)],
            ['group' => 'fundraising', 'label' => 'Beban Fundraising (Komisi Platform KM)', 'total' => $this->fmt($bebanFundraising)],
        ];

        $penghasilan = [
            'pendapatan' => [
                'rows' => $pendapatanRows,
                'tp' => $this->fmt($revTpPeriod),
                'dp' => $this->fmt($revDpPeriod),
                'total' => $this->fmt($revTpPeriod + $revDpPeriod),
            ],
            'beban' => ['rows' => $bebanRows, 'total' => $this->fmt($bebanTotal)],
            // Pindah kelas, bukan penghasilan baru: DP berkurang, TP
            // bertambah, totalnya nol.
            'pelepasan_pembatasan' => $this->tdpTriple($pelepasan, -$pelepasan),
            'kenaikan' => [
                'tp' => $this->fmt($kenaikanTp),
                'dp' => $this->fmt($kenaikanDp),
                'total' => $this->fmt($kenaikanTp + $kenaikanDp),
            ],
        ];

        // ---------- Laporan Perubahan Aset Neto ----------
        $perubahan = [
            'awal' => $this->tdpTriple($awalTp, $awalDp),
            'pendapatan' => $this->tdpTriple($revTpPeriod, $revDpPeriod),
            'beban' => $this->tdpTriple(-$bebanTotal, 0.0),
            'pelepasan_pembatasan' => $this->tdpTriple($pelepasan, -$pelepasan),
            'kenaikan' => $this->tdpTriple($kenaikanTp, $kenaikanDp),
            'akhir' => $this->tdpTriple($akhirTp, $akhirDp),
        ];

        // ---------- Laporan Arus Kas (metode langsung) ----------
        [$arusKas, $arusKasBersih] = $this->arusKas($viharaFilter, $startD, $endD, $before, $kasTotal);

        return [
            'posisi' => $posisi,
            'penghasilan' => $penghasilan,
            'perubahan' => $perubahan,
            'arus_kas' => $arusKas,
            'raw' => [
                'pendapatan_tp' => $revTpPeriod,
                'pendapatan_dp' => $revDpPeriod,
                'beban_program' => $bebanProgram,
                'beban_admin' => $bebanAdmin,
                'beban_fundraising' => $bebanFundraising,
                'kenaikan_tp' => $kenaikanTp,
                'kenaikan_dp' => $kenaikanDp,
                'kas_setara' => $kasTotal,
                'pg_clearing' => $pgClearing,
                'aset_lainnya' => $asetLainTotal,
                'rekonsiliasi_sementara' => $rekonSementara,
                'total_aset' => $totalAset,
                'liabilitas' => $liabTotal,
                'aset_neto_tp' => $akhirTp,
                'aset_neto_dp' => $akhirDp,
                'selisih' => $selisih,
                'arus_kas_bersih' => $arusKasBersih,
            ],
        ];
    }

    /**
     * Satu entry ringkas konsolidasi dari angka raw. $vihara null =
     * blok total konsolidasi (tanpa identitas vihara).
     *
     * @param array<string, float> $raw
     */
    private function konsolidasiEntry(?array $vihara, array $raw): array
    {
        $entry = $vihara === null ? [] : ['vihara' => $vihara];

        return $entry + [
            'pendapatan' => [
                'tp' => $this->fmt($raw['pendapatan_tp']),
                'dp' => $this->fmt($raw['pendapatan_dp']),
                'total' => $this->fmt($raw['pendapatan_tp'] + $raw['pendapatan_dp']),
            ],
            'beban' => [
                'program' => $this->fmt($raw['beban_program']),
                'admin' => $this->fmt($raw['beban_admin']),
                'fundraising' => $this->fmt($raw['beban_fundraising']),
                'total' => $this->fmt($raw['beban_program'] + $raw['beban_admin'] + $raw['beban_fundraising']),
            ],
            'kenaikan' => [
                'tp' => $this->fmt($raw['kenaikan_tp']),
                'dp' => $this->fmt($raw['kenaikan_dp']),
                'total' => $this->fmt($raw['kenaikan_tp'] + $raw['kenaikan_dp']),
            ],
            'posisi' => [
                'kas_setara' => $this->fmt($raw['kas_setara']),
                'pg_clearing' => $this->fmt($raw['pg_clearing']),
                'aset_lainnya' => $this->fmt($raw['aset_lainnya']),
                'rekonsiliasi_sementara' => $this->fmt($raw['rekonsiliasi_sementara']),
                'total_aset' => $this->fmt($raw['total_aset']),
                'liabilitas' => $this->fmt($raw['liabilitas']),
                'aset_neto' => [
                    'tp' => $this->fmt($raw['aset_neto_tp']),
                    'dp' => $this->fmt($raw['aset_neto_dp']),
                    'total' => $this->fmt($raw['total_aset'] - $raw['liabilitas']),
                ],
                'balance_ok' => abs($raw['selisih']) < 0.005,
                'selisih' => $this->fmt($raw['selisih']),
            ],
            'arus_kas_bersih' => $this->fmt($raw['arus_kas_bersih']),
        ];
    }

    /**
     * @param array<string, float>|null $totals
     * @param array<string, float> $raw
     * @return array<string, float>
     */
    private function accumulateRaw(?array $totals, array $raw): array
    {
        if ($totals === null) {
            return $raw;
        }
        foreach ($raw as $key => $value) {
            $totals[$key] += $value;
        }

        return $totals;
    }

    /** Ada nilai berarti (≥ 0.005 absolut) di salah satu angka raw? */
    private function rawHasValue(array $raw): bool
    {
        foreach ($raw as $value) {
            if (abs($value) >= 0.005) {
                return true;
            }
        }

        return false;
    }

    /**
     * Net (debit − kredit) per account_code dari jurnal posted, difilter
     * vihara + rentang tanggal. Satu query agregat, tanpa loop per baris.
     *
     * @return array<string, float>
     */
    private function netByAccount(array $viharaFilter, ?string $from, ?string $to): array
    {
        return DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->whereNotNull('je.posted_at')
            ->tap(fn ($q) => $this->applyViharaFilter($q, $viharaFilter))
            ->when($from, fn ($q) => $q->where('je.entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('je.entry_date', '<=', $to))
            ->groupBy('jl.account_code')
            ->selectRaw('jl.account_code AS code, SUM(jl.debit - jl.credit) AS net')
            ->pluck('net', 'code')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * Arus kas operasi: baris jurnal pada akun KAS dalam periode, net per
     * baris, EXCLUDING jurnal transfer internal (semua barisnya di dalam
     * kelompok KAS, mis. topup kas kecil Dr 1120 / Kr 1130). Dikelompokkan
     * per sumber dokumen dalam satu query agregat.
     *
     * @return array{0: array, 1: float} [payload arus_kas, arus kas bersih (total operasi)]
     */
    private function arusKas(array $viharaFilter, string $startD, string $endD, array $before, float $kasAkhirPosisi): array
    {
        $kasList = "'" . implode("','", self::KAS) . "'";
        $programPrefix = self::PREFIX_PROGRAM;

        $bucketExpr = "CASE"
            . " WHEN je.reference_type = 'penerimaan' THEN"
            . " CASE WHEN pn.method = 'tunai' THEN 'penerimaan_tunai'"
            . " WHEN pn.method = 'auto_credit' THEN 'penerimaan_km_settled'"
            . " ELSE 'penerimaan_transfer' END"
            . " WHEN je.reference_type = 'pembayaran' THEN"
            . " CASE WHEN pb.debit_account_code LIKE '{$programPrefix}%' THEN 'pembayaran_program'"
            . " ELSE 'pembayaran_admin' END"
            . " WHEN je.reference_type IN ('petty_cash', 'petty_cash_movement') THEN 'petty_usage'"
            . " ELSE 'lainnya' END";

        $sums = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->leftJoin('penerimaan as pn', function ($join) {
                $join->on('pn.id', '=', 'je.reference_id')->where('je.reference_type', 'penerimaan');
            })
            ->leftJoin('pembayaran as pb', function ($join) {
                $join->on('pb.id', '=', 'je.reference_id')->where('je.reference_type', 'pembayaran');
            })
            ->whereNotNull('je.posted_at')
            ->tap(fn ($q) => $this->applyViharaFilter($q, $viharaFilter))
            ->whereBetween('je.entry_date', [$startD, $endD])
            ->whereIn('jl.account_code', self::KAS)
            // Bukan transfer internal: JE punya minimal satu baris non-KAS.
            ->whereExists(function ($q) use ($kasList) {
                $q->selectRaw('1')
                    ->from('journal_lines as x')
                    ->whereColumn('x.journal_entry_id', 'je.id')
                    ->whereRaw("x.account_code NOT IN ({$kasList})");
            })
            ->groupByRaw('1')
            ->selectRaw("{$bucketExpr} AS bucket, SUM(jl.debit - jl.credit) AS amount")
            ->pluck('amount', 'bucket')
            ->map(fn ($v) => (float) $v);

        $rows = [];
        $total = 0.0;
        foreach (['penerimaan_transfer', 'penerimaan_tunai', 'penerimaan_km_settled', 'pembayaran_program', 'pembayaran_admin', 'petty_usage'] as $key) {
            $amount = (float) ($sums[$key] ?? 0.0);
            $total += $amount;
            $rows[] = ['key' => $key, 'label' => self::ARUS_KAS_LABELS[$key], 'amount' => $this->fmt($amount)];
        }
        // Jurnal lain yang menyentuh kas (mis. manual JE) — hanya tampil bila ada.
        $lainnya = (float) ($sums['lainnya'] ?? 0.0);
        if (abs($lainnya) >= 0.005) {
            $total += $lainnya;
            $rows[] = ['key' => 'lainnya', 'label' => self::ARUS_KAS_LABELS['lainnya'], 'amount' => $this->fmt($lainnya)];
        }

        $kasAwal = $this->sumCodes($before, self::KAS);
        $kasAkhir = $kasAwal + $total;

        $payload = [
            'operasi' => ['rows' => $rows, 'total' => $this->fmt($total)],
            'investasi' => ['rows' => [], 'total' => '0.00'],
            'pendanaan' => ['rows' => [], 'total' => '0.00'],
            'kas_awal' => $this->fmt($kasAwal),
            'kas_akhir' => $this->fmt($kasAkhir),
            'check_kas_akhir_ok' => abs($kasAkhir - $kasAkhirPosisi) < 0.005,
        ];

        return [$payload, $total];
    }

    /**
     * Terapkan filter dimensi vihara ke query jurnal.
     *
     * Bentuk filter: ['ids' => ?array<int,int>, 'yayasan' => bool]
     *   ids null   = tanpa batas (hanya untuk scope global)
     *   ids []     = tidak ada vihara; dipadu yayasan=true menghasilkan
     *                "hanya jurnal level yayasan"
     *   yayasan    = ikutkan baris je.vihara_id NULL
     *
     * @param  array{ids: ?array<int, int>, yayasan: bool}  $viharaFilter
     */
    private function applyViharaFilter($query, array $viharaFilter): void
    {
        $ids = $viharaFilter['ids'];

        if ($ids === null) {
            return;
        }

        $includeYayasan = $viharaFilter['yayasan'];

        $query->where(function ($q) use ($ids, $includeYayasan) {
            $q->whereIn('je.vihara_id', $ids);
            if ($includeYayasan) {
                $q->orWhereNull('je.vihara_id');
            }
        });
    }

    /** @return array{ids: ?array<int, int>, yayasan: bool} */
    private static function filterViharas(?array $ids, bool $includeYayasan = false): array
    {
        return ['ids' => $ids, 'yayasan' => $includeYayasan];
    }

    /** @return array{ids: ?array<int, int>, yayasan: bool} */
    private static function filterYayasanOnly(): array
    {
        return ['ids' => [], 'yayasan' => true];
    }

    /**
     * Split pendapatan (semua akun 4xxx) menjadi [tp, dp], credit-normal.
     *
     * @param array<string, float> $net
     * @return array{0: float, 1: float}
     */
    /**
     * Kode akun aktif per jenis, langsung dari bagan akun (FV-57).
     *
     * Laporan dulu memakai daftar kode yang ditulis tetap di kelas ini, jadi
     * akun yang ditambahkan admin lewat menu Chart of Accounts tidak pernah
     * muncul di laporan mana pun — saldonya hilang tanpa jejak. Header
     * (1100, 2100, 4100, …) dikecualikan: itu hanya pengelompokan, saldonya
     * ada di anak-anaknya.
     *
     * @return list<string>
     */
    private function kodeAkun(string $type, ?string $prefix = null, array $kecuali = []): array
    {
        static $cache = [];
        $kunci = $type.'|'.$prefix.'|'.implode(',', $kecuali);
        if (isset($cache[$kunci])) {
            return $cache[$kunci];
        }

        $kode = Account::query()
            ->where('type', $type)
            ->where('is_header', false)
            ->when($prefix !== null, fn ($q) => $q->where('code', 'like', $prefix.'%'))
            ->orderBy('code')
            ->pluck('code')
            ->reject(fn ($c) => in_array($c, $kecuali, true))
            ->values()
            ->all();

        return $cache[$kunci] = $kode;
    }

    /** Kas & setara kas: seluruh aset 11xx kecuali clearing payment gateway. */
    private function kodeKas(): array
    {
        $kode = $this->kodeAkun('asset', '11', [self::AKUN_PG_CLEARING]);

        return $kode !== [] ? $kode : self::KAS;
    }

    private function kodeLiabilitas(): array
    {
        $kode = $this->kodeAkun('liability');

        return $kode !== [] ? $kode : self::LIABILITAS;
    }

    /**
     * Saldo akun ASET NETO (3xxx) dari sebuah peta saldo, dipisah TP/DP.
     *
     * Akun ekuitas bersaldo normal kredit, jadi tandanya dibalik. Kode
     * berawalan 32 = Dengan Pembatasan; selain itu Tanpa Pembatasan.
     *
     * @param array<string, float> $net
     * @return array{0: float, 1: float}
     */
    private function asetNetoSplit(array $net): array
    {
        $tp = 0.0;
        $dp = 0.0;
        foreach ($this->kodeAkun('equity') as $code) {
            $saldo = -($net[$code] ?? 0.0);
            if (str_starts_with($code, '32')) {
                $dp += $saldo;
            } else {
                $tp += $saldo;
            }
        }

        return [$tp, $dp];
    }

    /**
     * Total pelepasan pembatasan yang dicatat dalam periode, untuk satu
     * filter vihara. Dibaca dari jurnalnya (PelepasanPembatasanService),
     * bukan dihitung ulang dari beban — yang dilepas bisa lebih kecil
     * daripada bebannya saat dana terikatnya tidak cukup.
     */
    private function pelepasanPembatasan(array $viharaFilter, string $startD, string $endD): float
    {
        // Alias 'je' dipakai supaya applyViharaFilter() yang sama bisa
        // dipakai ulang — filternya menulis kolom je.vihara_id.
        $q = DB::table('journal_entries as je')
            ->where('je.reference_type', \App\Services\PelepasanPembatasanService::REFERENSI)
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$startD, $endD]);

        $this->applyViharaFilter($q, $viharaFilter);

        return (float) $q->sum('je.total_debit');
    }

    private function revenueSplit(array $net): array
    {
        $tp = 0.0;
        $dp = 0.0;
        foreach ($net as $code => $value) {
            if (! str_starts_with((string) $code, '4')) {
                continue;
            }
            if ($this->isDpRevenue((string) $code)) {
                $dp += -$value;
            } else {
                $tp += -$value;
            }
        }

        return [$tp, $dp];
    }

    /**
     * DP = digit ke-3 kode pendapatan '2' (4120/4220/4320 — dengan
     * pembatasan); digit ke-3 '1' (4110/4210/4310) = TP. Digit ke-2 adalah
     * kanal (1 KM, 2 transfer, 3 tunai), bukan penanda pembatasan.
     */
    private function isDpRevenue(string $code): bool
    {
        return in_array($code, self::DP_REV, true)
            || (strlen($code) === 4 && $code[0] === '4' && $code[2] === '2');
    }

    /** Total seluruh beban (program 5xxx + admin 6xxx + fundraising 7xxx), debit-normal. */
    private function bebanTotal(array $net): float
    {
        return $this->sumPrefix($net, self::PREFIX_PROGRAM)
            + $this->sumPrefix($net, self::PREFIX_ADMIN)
            + $this->sumPrefix($net, self::PREFIX_FUNDRAISING);
    }

    /**
     * Total net semua akun berawalan prefix.
     *
     * @param array<string, float> $net
     */
    private function sumPrefix(array $net, string $prefix): float
    {
        $sum = 0.0;
        foreach ($net as $code => $value) {
            if (str_starts_with((string) $code, $prefix)) {
                $sum += $value;
            }
        }

        return $sum;
    }

    /**
     * Kode akun (terurut) di $net yang berawalan prefix.
     *
     * @param array<string, float> $net
     * @return list<string>
     */
    private function codesWithPrefix(array $net, string $prefix): array
    {
        $codes = array_values(array_filter(
            array_map('strval', array_keys($net)),
            fn (string $code) => str_starts_with($code, $prefix),
        ));
        sort($codes);

        return $codes;
    }

    /** @param array<string, float> $net */
    private function sumCodes(array $net, array $codes): float
    {
        $sum = 0.0;
        foreach ($codes as $code) {
            $sum += $net[$code] ?? 0.0;
        }

        return $sum;
    }

    /** @return array{tp: string, dp: string, total: string} */
    private function tdpTriple(float $tp, float $dp): array
    {
        return [
            'tp' => $this->fmt($tp),
            'dp' => $this->fmt($dp),
            'total' => $this->fmt($tp + $dp),
        ];
    }

    private function fmt(float $value): string
    {
        $value = round($value, 2);
        if ($value == 0.0) {
            $value = 0.0; // hindari "-0.00"
        }

        return number_format($value, 2, '.', '');
    }
}
