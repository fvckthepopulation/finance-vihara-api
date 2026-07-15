<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pembayaran;
use App\Models\Penerimaan;
use App\Models\Vihara;
use App\Services\ViharaScopeService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Semua Transaksi — riwayat gabungan penerimaan + pembayaran (UNION ALL)
 * dengan filter, totals, dan export CSV.
 */
class TransactionController extends Controller
{
    /** Label pendek per kategori (mengikuti dashboard). */
    private const PENERIMAAN_LABELS = [
        'dana_operasional' => 'Dana Operasional',
        'dana_paramita'    => 'Dana Paramita',
        'dana_bhikkhu'     => 'Dana Bhikkhu',
        'dana_perayaan'    => 'Dana Perayaan',
        'donasi_belum_teridentifikasi' => 'Donasi Belum Teridentifikasi',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $this->validateFilters($request);

        $wrapped = $this->filteredQuery($request);

        $totals = (clone $wrapped)->selectRaw(
            "COALESCE(SUM(CASE WHEN tipe = 'Penerimaan' THEN amount ELSE 0 END), 0) AS masuk, "
            . "COALESCE(SUM(CASE WHEN tipe = 'Pengeluaran' THEN amount ELSE 0 END), 0) AS keluar, "
            . 'COUNT(*) AS cnt',
        )->first();

        $perPage = min(max($request->integer('per_page') ?: 25, 1), 200);
        $bankLabels = $this->bankLabels();
        $paginator = (clone $wrapped)
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn ($row) => [
                'id' => (int) $row->id,
                'tipe' => $row->tipe,
                'code' => $row->code,
                'date' => $row->date,
                'name' => $row->name,
                'category' => $row->category,
                'category_label' => $this->categoryLabel($row->category),
                'amount' => $row->amount,
                'method' => $row->method,
                'source' => $row->source,
                'status' => $row->status,
                'counterparty' => $row->counterparty,
                'reference' => $row->reference,
                'description' => $row->description,
                'vihara_id' => $row->vihara_id !== null ? (int) $row->vihara_id : null,
                'bank_account_id' => $row->bank_account_id !== null ? (int) $row->bank_account_id : null,
                'bank_label' => $row->bank_account_id !== null ? ($bankLabels[(int) $row->bank_account_id] ?? null) : null,
            ]);

        return response()->json($paginator->toArray() + [
            'totals' => [
                'masuk' => number_format((float) $totals->masuk, 2, '.', ''),
                'keluar' => number_format((float) $totals->keluar, 2, '.', ''),
                'count' => (int) $totals->cnt,
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeView($request);
        $this->validateFilters($request);

        $rows = $this->filteredQuery($request)->orderByDesc('date')->orderByDesc('id');
        $viharaNames = Vihara::query()->pluck('name', 'id');

        return response()->streamDownload(function () use ($rows, $viharaNames) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM supaya Excel baca benar
            fputcsv($out, [
                'Tanggal', 'Tipe', 'Kode', 'Nama', 'Kategori', 'Jumlah', 'Metode',
                'Sumber', 'Status', 'Donatur/Penerima', 'Vihara', 'Catatan',
            ]);
            foreach ($rows->cursor() as $row) {
                fputcsv($out, [
                    $row->date,
                    $row->tipe,
                    $row->code,
                    $row->name,
                    $this->categoryLabel($row->category),
                    $row->amount,
                    $row->method,
                    $row->source,
                    $row->status,
                    $row->counterparty,
                    $row->vihara_id !== null ? ($viharaNames[$row->vihara_id] ?? $row->vihara_id) : null,
                    $row->description,
                ]);
            }
            fclose($out);
        }, 'transaksi_vihara.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** Boleh lihat riwayat gabungan asal punya salah satu izin view transaksi. */
    private function authorizeView(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->can('penerimaan.view') || $user->can('pembayaran.view'), 403);
    }

    private function validateFilters(Request $request): void
    {
        $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tipe' => ['sometimes', 'nullable', 'in:Penerimaan,Pengeluaran'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'sumber' => ['sometimes', 'nullable', 'in:km,manual'],
            'status' => ['sometimes', 'nullable', 'in:draft,posted'],
            'vihara_id' => ['sometimes', 'integer', 'exists:viharas,id'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
    }

    /**
     * Bangun query gabungan (UNION ALL penerimaan + pembayaran) yang sudah
     * ter-filter, dibungkus subquery supaya bisa di-order/paginate/agregat.
     */
    private function filteredQuery(Request $request): Builder
    {
        $viharaId = ViharaScopeService::readFilter($request);
        $tipe = $request->string('tipe')->toString();
        $sumber = $request->string('sumber')->toString();

        $branches = [];

        if ($tipe !== 'Pengeluaran') {
            $p = DB::table('penerimaan')->selectRaw(
                "id, 'Penerimaan' AS tipe, code, date, name, category, amount, method, "
                . "CASE WHEN method = 'auto_credit' THEN 'km' ELSE 'manual' END AS source, "
                . 'status, source_name AS counterparty, reference, description, vihara_id, bank_account_id',
            );
            if ($sumber === 'km') {
                $p->where('method', 'auto_credit');
            } elseif ($sumber === 'manual') {
                $p->where('method', '!=', 'auto_credit');
            }
            $branches[] = $this->applyCommonFilters($p, $request, $viharaId, 'source_name');
        }

        if ($tipe !== 'Penerimaan') {
            $b = DB::table('pembayaran')->selectRaw(
                "id, 'Pengeluaran' AS tipe, code, date, name, category, amount, method, "
                . "'manual' AS source, status, payee_name AS counterparty, reference, description, vihara_id, bank_account_id",
            );
            if ($sumber === 'km') {
                // Semua pengeluaran adalah manual — filter km mengosongkan branch ini.
                $b->whereRaw('1 = 0');
            }
            $branches[] = $this->applyCommonFilters($b, $request, $viharaId, 'payee_name');
        }

        $union = array_shift($branches);
        foreach ($branches as $branch) {
            $union->unionAll($branch);
        }

        return DB::query()->fromSub($union, 't');
    }

    private function applyCommonFilters(Builder $q, Request $request, ?int $viharaId, string $counterpartyColumn): Builder
    {
        // Cancelled tidak pernah tampil di riwayat.
        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        } else {
            $q->whereIn('status', ['draft', 'posted']);
        }

        if ($viharaId) {
            $q->where('vihara_id', $viharaId);
        }
        if ($category = $request->string('category')->toString()) {
            $q->where('category', $category);
        }
        if ($from = $request->string('from')->toString()) {
            $q->where('date', '>=', $from);
        }
        if ($to = $request->string('to')->toString()) {
            $q->where('date', '<=', $to);
        }
        if ($search = $request->string('search')->toString()) {
            $q->where(function ($w) use ($search, $counterpartyColumn) {
                $like = '%' . $search . '%';
                $w->where('name', 'ilike', $like)
                    ->orWhere('code', 'ilike', $like)
                    ->orWhere('reference', 'ilike', $like)
                    ->orWhere('description', 'ilike', $like)
                    ->orWhere($counterpartyColumn, 'ilike', $like);
            });
        }

        return $q;
    }

    /** Peta id rekening → label pendek ("BCA 6280-3488-88"). Tabel kecil. */
    private function bankLabels(): array
    {
        return \App\Models\ViharaBankAccount::query()
            ->get(['id', 'bank_name', 'account_number'])
            ->mapWithKeys(fn ($a) => [$a->id => $a->label()])
            ->all();
    }

    private function categoryLabel(string $category): string
    {
        if (isset(self::PENERIMAAN_LABELS[$category])) {
            return self::PENERIMAAN_LABELS[$category];
        }
        if (isset(Pembayaran::CATEGORIES[$category])) {
            return Pembayaran::CATEGORIES[$category]['label'];
        }

        return Penerimaan::CATEGORIES[$category]['label'] ?? ucwords(str_replace('_', ' ', $category));
    }
}
