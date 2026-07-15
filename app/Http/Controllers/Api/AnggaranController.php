<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Services\ViharaScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anggaran vs realisasi per vihara per tahun. Target disimpan di tabel
 * budgets; realisasi dihitung dari transaksi posted tahun tsb (penerimaan
 * per kategori dana — suspense tidak dihitung; pengeluaran per kategori
 * biaya + detail per Nama Pengeluaran).
 */
class AnggaranController extends Controller
{
    /** Kategori penerimaan yang dianggarkan (suspense 2310 dikecualikan). */
    private const PENERIMAAN_CATEGORIES = [
        'dana_operasional' => 'Dana Operasional',
        'dana_paramita' => 'Dana Paramita',
        'dana_bhikkhu' => 'Dana Bhikkhu',
        'dana_perayaan' => 'Dana Perayaan',
    ];

    /** Kategori pengeluaran yang dianggarkan. */
    private const PENGELUARAN_CATEGORIES = [
        'biaya_operasional' => 'Biaya Operasional',
        'biaya_paramita' => 'Biaya Paramita',
        'biaya_bhikkhu' => 'Biaya Bhikkhu',
        'biaya_perayaan' => 'Biaya Perayaan',
    ];

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'vihara_id' => ['sometimes', 'integer', 'exists:viharas,id'],
        ]);

        $viharaId = ViharaScopeService::readFilter($request);
        if ($viharaId === null) {
            // Anggaran selalu per vihara — admin yayasan wajib memilih.
            throw ValidationException::withMessages([
                'vihara_id' => ['vihara_id wajib diisi (anggaran per vihara).'],
            ]);
        }

        return response()->json($this->payload((int) $request->input('year'), $viharaId));
    }

    /**
     * Bulk upsert target: {year, vihara_id, items:[{type, category, name?, amount}]}.
     * amount 0 menghapus row target tsb. Respon = payload GET terbaru.
     */
    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'vihara_id' => ['sometimes', 'integer', 'exists:viharas,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', 'in:penerimaan,pengeluaran'],
            'items.*.category' => ['required', 'string', 'max:64'],
            'items.*.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'items.*.amount' => ['required', 'numeric', 'min:0'],
        ]);

        $viharaId = ViharaScopeService::creationViharaId($request);

        // Kategori harus salah satu kategori teranggarkan (sesuai tampilan GET).
        foreach ($data['items'] as $i => $item) {
            $allowed = $item['type'] === 'penerimaan'
                ? array_keys(self::PENERIMAAN_CATEGORIES)
                : array_keys(self::PENGELUARAN_CATEGORIES);
            if (! in_array($item['category'], $allowed, true)) {
                throw ValidationException::withMessages([
                    "items.{$i}.category" => ['Kategori tidak dikenal untuk type ' . $item['type'] . '.'],
                ]);
            }
        }

        DB::transaction(function () use ($data, $viharaId) {
            foreach ($data['items'] as $item) {
                $key = [
                    'vihara_id' => $viharaId,
                    'year' => (int) $data['year'],
                    'type' => $item['type'],
                    'category' => $item['category'],
                    'name' => ($item['name'] ?? null) !== '' ? ($item['name'] ?? null) : null,
                ];

                if ((float) $item['amount'] <= 0.0) {
                    Budget::where($key)->delete();
                } else {
                    Budget::updateOrCreate($key, ['amount' => $item['amount']]);
                }
            }
        });

        return response()->json($this->payload((int) $data['year'], $viharaId));
    }

    private function payload(int $year, int $viharaId): array
    {
        $range = [sprintf('%d-01-01', $year), sprintf('%d-12-31', $year)];

        $budgets = Budget::query()
            ->where('vihara_id', $viharaId)
            ->where('year', $year)
            ->get();

        $categoryTargets = []; // [type][category] => amount
        $detailTargets = [];   // [category][name] => amount (pengeluaran)
        foreach ($budgets as $b) {
            if ($b->name === null) {
                $categoryTargets[$b->type][$b->category] = (float) $b->amount;
            } elseif ($b->type === 'pengeluaran') {
                $detailTargets[$b->category][$b->name] = (float) $b->amount;
            }
        }

        // Realisasi = transaksi posted tahun berjalan (agregat SQL).
        $realisasiIn = DB::table('penerimaan')
            ->where('status', 'posted')
            ->where('vihara_id', $viharaId)
            ->whereBetween('date', $range)
            ->groupBy('category')
            ->selectRaw('category, SUM(amount) AS total')
            ->pluck('total', 'category');

        $realisasiOut = DB::table('pembayaran')
            ->where('status', 'posted')
            ->where('vihara_id', $viharaId)
            ->whereBetween('date', $range)
            ->groupBy('category')
            ->selectRaw('category, SUM(amount) AS total')
            ->pluck('total', 'category');

        $realisasiDetail = DB::table('pembayaran')
            ->where('status', 'posted')
            ->where('vihara_id', $viharaId)
            ->whereBetween('date', $range)
            ->whereNotNull('name')
            ->groupBy('category', 'name')
            ->selectRaw('category, name, SUM(amount) AS total')
            ->get();

        $penerimaan = [];
        foreach (self::PENERIMAAN_CATEGORIES as $category => $label) {
            $penerimaan[] = $this->row($category, $label,
                $categoryTargets['penerimaan'][$category] ?? 0.0,
                (float) ($realisasiIn[$category] ?? 0.0),
            );
        }

        $pengeluaran = [];
        foreach (self::PENGELUARAN_CATEGORIES as $category => $label) {
            $pengeluaran[] = $this->row($category, $label,
                $categoryTargets['pengeluaran'][$category] ?? 0.0,
                (float) ($realisasiOut[$category] ?? 0.0),
            );
        }

        // Detail per Nama Pengeluaran: gabungan nama ber-target + nama
        // dengan realisasi > 0.
        $detailRealisasi = []; // [category][name] => total
        foreach ($realisasiDetail as $r) {
            $detailRealisasi[$r->category][$r->name] = (float) $r->total;
        }

        $detail = [];
        $categories = array_unique(array_merge(array_keys($detailTargets), array_keys($detailRealisasi)));
        sort($categories);
        foreach ($categories as $category) {
            $names = array_unique(array_merge(
                array_keys($detailTargets[$category] ?? []),
                array_keys($detailRealisasi[$category] ?? []),
            ));
            sort($names);
            foreach ($names as $name) {
                $target = $detailTargets[$category][$name] ?? 0.0;
                $realisasi = $detailRealisasi[$category][$name] ?? 0.0;
                if ($target <= 0.0 && $realisasi <= 0.0) {
                    continue;
                }
                $detail[] = [
                    'category' => $category,
                    'name' => $name,
                    'target' => $this->fmt($target),
                    'realisasi' => $this->fmt($realisasi),
                ];
            }
        }

        return [
            'year' => $year,
            'vihara_id' => $viharaId,
            'penerimaan' => $penerimaan,
            'pengeluaran' => $pengeluaran,
            'detail' => $detail,
        ];
    }

    private function row(string $category, string $label, float $target, float $realisasi): array
    {
        return [
            'category' => $category,
            'label' => $label,
            'target' => $this->fmt($target),
            'realisasi' => $this->fmt($realisasi),
            'pct' => $target > 0 ? (int) round($realisasi / $target * 100) : null,
        ];
    }

    private function fmt(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
