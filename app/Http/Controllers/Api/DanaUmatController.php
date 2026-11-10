<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Penerimaan;
use App\Services\ViharaScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Penerimaan Dana Umat — rekap penerimaan posted per kategori dana
 * (donasi belum teridentifikasi tidak termasuk). Periode YTD/bulanan,
 * semantik sama dengan /api/dashboard.
 */
class DanaUmatController extends Controller
{
    /** Urutan kategori tetap sesuai mockup. */
    private const CATEGORY_ORDER = ['dana_perayaan', 'dana_operasional', 'dana_bhikkhu', 'dana_paramita'];

    private const LABELS = [
        'dana_perayaan'    => 'Dana Perayaan',
        'dana_operasional' => 'Dana Operasional',
        'dana_bhikkhu'     => 'Dana Bhikkhu',
        'dana_paramita'    => 'Dana Paramita',
    ];

    /** Batas baris detail per kategori. */
    private const ROWS_CAP = 100;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['sometimes', 'in:ytd,bulan'],
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required_if:mode,bulan', 'integer', 'min:1', 'max:12'],
            'vihara_id' => ['sometimes', 'integer', 'exists:viharas,id'],
        ]);

        $viharaIds = ViharaScopeService::readFilterIds($request);

        $mode = $data['mode'] ?? 'ytd';
        $year = (int) ($data['year'] ?? now()->year);
        $month = $mode === 'bulan' ? (int) $data['month'] : null;

        $start = CarbonImmutable::create($year, $month ?? 1, 1);
        $end = $mode === 'bulan' ? $start->endOfMonth() : $start->endOfYear();
        $range = [$start->toDateString(), $end->toDateString()];

        $base = fn () => Penerimaan::query()
            ->where('status', 'posted')
            ->whereBetween('date', $range)
            ->when($viharaIds !== null, fn ($q) => $q->whereIn('vihara_id', $viharaIds));

        // Count + total dihitung atas seluruh set (bukan hanya baris yang di-cap).
        $agg = $base()
            ->whereIn('category', self::CATEGORY_ORDER)
            ->groupBy('category')
            ->select('category', DB::raw('COUNT(*) AS tx_count'), DB::raw('SUM(amount) AS tx_total'))
            ->get()
            ->keyBy('category');

        $categories = [];
        foreach (self::CATEGORY_ORDER as $category) {
            $rows = $base()
                ->where('category', $category)
                ->orderByDesc('date')->orderByDesc('id')
                ->limit(self::ROWS_CAP)
                ->get(['id', 'date', 'name', 'source_name', 'received_by', 'method', 'description', 'amount'])
                ->map(fn (Penerimaan $r) => [
                    'id' => $r->id,
                    'date' => $r->date->toDateString(),
                    'name' => $r->name,
                    'donor' => $r->source_name,
                    'received_by' => $r->received_by,
                    'method' => $r->method,
                    'source' => $r->method === 'auto_credit' ? 'km' : 'manual',
                    'description' => $r->description,
                    'amount' => $r->amount,
                ])
                ->values();

            $categories[] = [
                'category' => $category,
                'label' => self::LABELS[$category],
                'count' => (int) ($agg[$category]->tx_count ?? 0),
                'total' => number_format((float) ($agg[$category]->tx_total ?? 0), 2, '.', ''),
                'rows' => $rows,
            ];
        }

        return response()->json([
            'period' => ['mode' => $mode, 'year' => $year, 'month' => $month],
            'categories' => $categories,
        ]);
    }
}
