<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pembayaran;
use App\Models\Penerimaan;
use App\Services\ViharaScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Label pendek kategori penerimaan untuk dashboard
     * (label COA di model menyertakan "(Tanpa/Dengan Pembatasan)" — terlalu panjang).
     */
    private const PENERIMAAN_LABELS = [
        'dana_operasional' => 'Dana Operasional',
        'dana_paramita'    => 'Dana Paramita',
        'dana_bhikkhu'     => 'Dana Bhikkhu',
        'dana_perayaan'    => 'Dana Perayaan',
    ];

    /**
     * Mapping kategori biaya → debet dana (mengikuti mockup dashboard):
     * komisi platform dibebankan ke Dana Operasional; utang lain-lain dan
     * biaya lainnya masuk keranjang "Lainnya".
     */
    private const BIAYA_DANA_MAP = [
        'biaya_operasional' => 'Dana Operasional',
        'biaya_paramita'    => 'Dana Paramita',
        'biaya_bhikkhu'     => 'Dana Bhikkhu',
        'biaya_perayaan'    => 'Dana Perayaan',
        'komisi_platform'   => 'Dana Operasional',
        'utang_lain'        => 'Lainnya',
        'biaya_lainnya'     => 'Lainnya',
    ];

    /**
     * Ringkasan dashboard — total penerimaan/pengeluaran posted dalam periode
     * (YTD atau per bulan), breakdown per jalur masuk dan per kategori dana.
     */
    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['sometimes', 'in:ytd,bulan'],
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required_if:mode,bulan', 'integer', 'min:1', 'max:12'],
            'vihara_id' => ['sometimes', 'integer', 'exists:viharas,id'],
        ]);

        $viharaId = ViharaScopeService::readFilter($request);

        $mode = $data['mode'] ?? 'ytd';
        $year = (int) ($data['year'] ?? now()->year);
        $month = $mode === 'bulan' ? (int) $data['month'] : null;

        $start = CarbonImmutable::create($year, $month ?? 1, 1);
        $end = $mode === 'bulan' ? $start->endOfMonth() : $start->endOfYear();
        $range = [$start->toDateString(), $end->toDateString()];

        // --- Penerimaan: satu query agregat per (category, method) ---
        $masukRows = Penerimaan::query()
            ->where('status', 'posted')
            ->whereBetween('date', $range)
            ->when($viharaId, fn ($q) => $q->where('vihara_id', $viharaId))
            ->groupBy('category', 'method')
            ->select('category', 'method', DB::raw('COUNT(*) as tx_count'), DB::raw('SUM(amount) as tx_total'))
            ->get();

        $channels = [
            'platform' => ['total' => 0.0, 'count' => 0], // method = auto_credit (jalur KalyanaMitta, Phase 7)
            'transfer' => ['total' => 0.0, 'count' => 0],
            'tunai'    => ['total' => 0.0, 'count' => 0],
        ];
        $byCategory = [];
        $suspense = ['total' => 0.0, 'count' => 0];
        $masukTotal = 0.0;
        $masukCount = 0;

        foreach ($masukRows as $row) {
            $total = (float) $row->tx_total;
            $count = (int) $row->tx_count;

            // Donasi belum teridentifikasi = suspense (2310), belum diakui pendapatan.
            if ($row->category === 'donasi_belum_teridentifikasi') {
                $suspense['total'] += $total;
                $suspense['count'] += $count;
                continue;
            }

            $masukTotal += $total;
            $masukCount += $count;

            $channel = $row->method === 'auto_credit' ? 'platform' : $row->method;
            if (isset($channels[$channel])) {
                $channels[$channel]['total'] += $total;
                $channels[$channel]['count'] += $count;
            }

            $byCategory[$row->category] ??= [
                'category' => $row->category,
                'label' => self::PENERIMAAN_LABELS[$row->category]
                    ?? (Penerimaan::CATEGORIES[$row->category]['label'] ?? $row->category),
                'count' => 0,
                'total' => 0.0,
            ];
            $byCategory[$row->category]['count'] += $count;
            $byCategory[$row->category]['total'] += $total;
        }

        $byCategory = array_values($byCategory);
        usort($byCategory, fn ($a, $b) => $b['total'] <=> $a['total']);

        // --- Pengeluaran: agregat per kategori → digabung per debet dana ---
        $keluarRows = Pembayaran::query()
            ->where('status', 'posted')
            ->whereBetween('date', $range)
            ->when($viharaId, fn ($q) => $q->where('vihara_id', $viharaId))
            ->groupBy('category')
            ->select('category', DB::raw('COUNT(*) as tx_count'), DB::raw('SUM(amount) as tx_total'))
            ->get();

        $byDana = [];
        $keluarTotal = 0.0;
        $keluarCount = 0;

        foreach ($keluarRows as $row) {
            $total = (float) $row->tx_total;
            $count = (int) $row->tx_count;
            $keluarTotal += $total;
            $keluarCount += $count;

            $dana = self::BIAYA_DANA_MAP[$row->category] ?? 'Lainnya';
            $byDana[$dana] ??= ['dana' => $dana, 'count' => 0, 'total' => 0.0];
            $byDana[$dana]['count'] += $count;
            $byDana[$dana]['total'] += $total;
        }

        $byDana = array_values($byDana);
        usort($byDana, fn ($a, $b) => $b['total'] <=> $a['total']);

        return response()->json([
            'period' => ['mode' => $mode, 'year' => $year, 'month' => $month],
            'penerimaan' => [
                'total' => $masukTotal,
                'count' => $masukCount,
                'by_channel' => $channels,
                'by_category' => $byCategory,
                'suspense' => $suspense,
            ],
            'pengeluaran' => [
                'total' => $keluarTotal,
                'count' => $keluarCount,
                'by_dana' => $byDana,
            ],
        ]);
    }
}
