<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
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
    /** Kas & setara kas. */
    private const KAS = ['1110', '1120', '1130', '1140'];

    /** Pendapatan tanpa pembatasan (TP) / dengan pembatasan (DP). */
    private const TP_REV = ['4110', '4120'];
    private const DP_REV = ['4210', '4220'];

    /** Kelompok beban (fungsional). */
    private const BEBAN_PROGRAM = ['5120', '5210', '5220'];
    private const BEBAN_ADMIN = ['5110'];
    private const BEBAN_FUNDRAISING = ['7140'];

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

    /**
     * Sentinel filter: HANYA jurnal level yayasan (je.vihara_id IS NULL).
     * Berbeda dgn null yang berarti "semua vihara termasuk level yayasan".
     */
    private const FILTER_YAYASAN_ONLY = 'yayasan_only';

    public function isak35(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['sometimes', 'in:ytd,bulan'],
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required_if:mode,bulan', 'integer', 'min:1', 'max:12'],
            'vihara_id' => ['sometimes', 'integer', 'exists:viharas,id'],
        ]);

        $viharaId = ViharaScopeService::readFilter($request);

        [$mode, $year, $month, $startD, $endD, $beforeD] = $this->resolvePeriod($data);

        $report = $this->buildReport($viharaId, $startD, $endD, $beforeD);

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
        if ($request->user()->vihara_id !== null) {
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

        foreach (Vihara::query()->orderBy('code')->get() as $vihara) {
            $raw = $this->buildReport((int) $vihara->id, $startD, $endD, $beforeD)['raw'];
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
        $yysRaw = $this->buildReport(self::FILTER_YAYASAN_ONLY, $startD, $endD, $beforeD)['raw'];
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
                'nama' => 'Yayasan Dhammavihara Indonesia',
                'singkatan' => 'YDI',
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
     * yayasan), FILTER_YAYASAN_ONLY = hanya jurnal ber-vihara_id NULL.
     *
     * Return: posisi/penghasilan/perubahan/arus_kas (payload isak35) + raw
     * (angka float sebelum format — dipakai konsolidasi agar penjumlahan
     * lintas vihara eksak, bukan parse ulang string).
     *
     * @return array{posisi: array, penghasilan: array, perubahan: array, arus_kas: array, raw: array<string, float>}
     */
    private function buildReport(int|string|null $viharaFilter, string $startD, string $endD, string $beforeD): array
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
        foreach (self::KAS as $code) {
            $bal = $cum[$code] ?? 0.0; // aset: debit-normal
            $kasTotal += $bal;
            $kasRows[] = ['code' => $code, 'name' => $names[$code] ?? $code, 'balance' => $this->fmt($bal)];
        }

        $pgClearing = $cum['1160'] ?? 0.0;
        $totalAset = $kasTotal + $pgClearing;

        $liabRows = [];
        $liabTotal = 0.0;
        foreach (self::LIABILITAS as $code) {
            $bal = -($cum[$code] ?? 0.0); // liabilitas: credit-normal
            $liabTotal += $bal;
            $liabRows[] = ['code' => $code, 'name' => $names[$code] ?? $code, 'balance' => $this->fmt($bal)];
        }

        // ---------- Pendapatan & beban (klasifikasi TP/DP) ----------
        [$revTpBefore, $revDpBefore] = $this->revenueSplit($before);
        [$revTpPeriod, $revDpPeriod] = $this->revenueSplit($period);

        $bebanBefore = $this->bebanTotal($before);
        $bebanProgram = $this->sumCodes($period, self::BEBAN_PROGRAM);
        $bebanAdmin = $this->sumCodes($period, self::BEBAN_ADMIN);
        $bebanFundraising = $this->sumCodes($period, self::BEBAN_FUNDRAISING);
        $bebanTotal = $bebanProgram + $bebanAdmin + $bebanFundraising;

        // Aset neto: seluruh beban dialokasikan ke TP (praktik mockup —
        // pengeluaran dana terikat dicatat sebagai beban TP).
        $awalTp = $revTpBefore - $bebanBefore;
        $awalDp = $revDpBefore;
        $kenaikanTp = $revTpPeriod - $bebanTotal;
        $kenaikanDp = $revDpPeriod;
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
            ['group' => 'program', 'label' => 'Beban Program (Paramita, Bhikkhu, Perayaan)', 'total' => $this->fmt($bebanProgram)],
            ['group' => 'admin', 'label' => 'Beban Administrasi & Umum', 'total' => $this->fmt($bebanAdmin)],
            ['group' => 'fundraising', 'label' => 'Beban Komisi Platform KM', 'total' => $this->fmt($bebanFundraising)],
        ];

        $penghasilan = [
            'pendapatan' => [
                'rows' => $pendapatanRows,
                'tp' => $this->fmt($revTpPeriod),
                'dp' => $this->fmt($revDpPeriod),
                'total' => $this->fmt($revTpPeriod + $revDpPeriod),
            ],
            'beban' => ['rows' => $bebanRows, 'total' => $this->fmt($bebanTotal)],
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
    private function netByAccount(int|string|null $viharaFilter, ?string $from, ?string $to): array
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
    private function arusKas(int|string|null $viharaFilter, string $startD, string $endD, array $before, float $kasAkhirPosisi): array
    {
        $kasList = "'" . implode("','", self::KAS) . "'";
        $programList = "'" . implode("','", self::BEBAN_PROGRAM) . "'";

        $bucketExpr = "CASE"
            . " WHEN je.reference_type = 'penerimaan' THEN"
            . " CASE WHEN pn.method = 'tunai' THEN 'penerimaan_tunai'"
            . " WHEN pn.method = 'auto_credit' THEN 'penerimaan_km_settled'"
            . " ELSE 'penerimaan_transfer' END"
            . " WHEN je.reference_type = 'pembayaran' THEN"
            . " CASE WHEN pb.debit_account_code IN ({$programList}) THEN 'pembayaran_program'"
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
     * Terapkan filter dimensi vihara ke query jurnal: int = satu vihara,
     * FILTER_YAYASAN_ONLY = hanya vihara_id NULL, null = tanpa filter.
     */
    private function applyViharaFilter($query, int|string|null $viharaFilter): void
    {
        if ($viharaFilter === self::FILTER_YAYASAN_ONLY) {
            $query->whereNull('je.vihara_id');
        } elseif ($viharaFilter !== null) {
            $query->where('je.vihara_id', $viharaFilter);
        }
    }

    /**
     * Split pendapatan (semua akun 4xxx) menjadi [tp, dp], credit-normal.
     *
     * @param array<string, float> $net
     * @return array{0: float, 1: float}
     */
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

    /** 42xx = pendapatan dengan pembatasan (DP); 4xxx lainnya = TP. */
    private function isDpRevenue(string $code): bool
    {
        return in_array($code, self::DP_REV, true) || str_starts_with($code, '42');
    }

    /** Total seluruh beban (program + admin + fundraising), debit-normal. */
    private function bebanTotal(array $net): float
    {
        return $this->sumCodes($net, array_merge(self::BEBAN_PROGRAM, self::BEBAN_ADMIN, self::BEBAN_FUNDRAISING));
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
