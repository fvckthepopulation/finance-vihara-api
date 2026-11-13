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

        $perPage = min(max($request->integer('per_page') ?: 20, 1), 200);
        $bankLabels = $this->bankLabels();
        // Nama vihara ikut dikirim: tanpa ini klien hanya punya id, dan tabel
        // lintas-vihara tidak bisa menunjukkan baris ini milik vihara mana.
        $viharaNames = \App\Models\Vihara::query()->pluck('name', 'id');
        // Nama pengaju — dipakai menu Persetujuan untuk memilah antrean.
        $namaPengguna = \App\Models\User::query()->pluck('name', 'id');
        // Cadangan label rekening. Baris yang lahir di luar form — mis.
        // komisi settlement KalyanaMitta — hanya punya kode akun kas/bank,
        // tanpa bank_account_id. Tanpa cadangan ini kolom Rekening-nya kosong
        // padahal rekeningnya jelas.
        $akunKas = \App\Models\Account::query()->pluck('name', 'code');
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
                'vihara_name' => $row->vihara_id !== null ? ($viharaNames[(int) $row->vihara_id] ?? null) : null,
                // Klien perlu tahu ADA tidaknya bukti tanpa mengunduhnya.
                'has_attachment' => (bool) $row->has_attachment,
                'voided_at' => $row->voided_at,
                'void_reason' => $row->void_reason,
                'submitted_at' => $row->submitted_at,
                'rejected_at' => $row->rejected_at,
                'reject_reason' => $row->reject_reason,
                'submitted_by' => $row->submitted_by_user_id !== null
                    ? ($namaPengguna[(int) $row->submitted_by_user_id] ?? null)
                    : null,
                'submitted_by_user_id' => $row->submitted_by_user_id !== null ? (int) $row->submitted_by_user_id : null,
                'bank_account_id' => $row->bank_account_id !== null ? (int) $row->bank_account_id : null,
                'bank_label' => $row->bank_account_id !== null
                    ? ($bankLabels[(int) $row->bank_account_id] ?? null)
                    : ($row->cash_account_code !== null
                        ? trim($row->cash_account_code . ' ' . ($akunKas[$row->cash_account_code] ?? ''))
                        : null),
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
            // No. Transaksi di depan, sama seperti kolom pertama di daftar
            // (FV-40): itulah pegangan saat mencocokkan CSV dengan jurnal.
            fputcsv($out, [
                'No. Transaksi', 'Tanggal', 'Tipe', 'Nama', 'Kategori', 'Jumlah', 'Metode',
                'Sumber', 'Status', 'Donatur/Penerima', 'Vihara', 'Catatan',
            ]);
            foreach ($rows->cursor() as $row) {
                fputcsv($out, [
                    $row->code,
                    $row->date,
                    $row->tipe,
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

    /**
     * Daftar per modul (Daftar Pengeluaran / Daftar Penerimaan, FV-38).
     *
     * Datanya sama dengan Semua Transaksi, izinnya tidak: daftar penerimaan
     * cukup butuh penerimaan.view, dan daftar pengeluaran pembayaran.view.
     * Tanpa rute tersendiri, kedua layar itu menumpang transaksi.view —
     * artinya role yang hanya boleh melihat penerimaannya sendiri harus
     * diberi izin menu Semua Transaksi sekadar supaya daftarnya hidup.
     *
     * Penjagaannya per `tipe`, sehingga izin satu modul tidak membuka modul
     * yang lain.
     */
    public function daftar(Request $request): JsonResponse
    {
        $user = $request->user();
        $tipe = $request->string('tipe')->toString();
        if ($tipe === 'Penerimaan') {
            abort_unless($user->can('penerimaan.view'), 403);
        } elseif ($tipe === 'Pengeluaran') {
            abort_unless($user->can('pembayaran.view'), 403);
        } else {
            // Gabungan keduanya = layar Semua Transaksi; itu izinnya sendiri.
            abort_unless($user->can('transaksi.view'), 403);
        }

        return $this->index($request);
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
            'status' => ['sometimes', 'nullable', 'in:draft,submitted,posted,rejected,cancelled'],
            'submitted_by' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
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
        $viharaIds = ViharaScopeService::readFilterIds($request);
        $tipe = $request->string('tipe')->toString();
        $sumber = $request->string('sumber')->toString();

        $branches = [];

        if ($tipe !== 'Pengeluaran') {
            $p = DB::table('penerimaan')->selectRaw(
                "id, 'Penerimaan' AS tipe, code, date, name, category, amount, method, "
                . "CASE WHEN method = 'auto_credit' THEN 'km' ELSE 'manual' END AS source, "
                . 'status, source_name AS counterparty, reference, description, vihara_id, bank_account_id, '
                . 'destination_account_code AS cash_account_code, '
                . 'CASE WHEN attachment_path IS NULL THEN 0 ELSE 1 END AS has_attachment, '
                . 'voided_at, void_reason, submitted_at, rejected_at, reject_reason, submitted_by_user_id',
            );
            if ($sumber === 'km') {
                $p->where('method', 'auto_credit');
            } elseif ($sumber === 'manual') {
                $p->where('method', '!=', 'auto_credit');
            }
            $branches[] = $this->applyCommonFilters($p, $request, $viharaIds, 'source_name');
        }

        if ($tipe !== 'Penerimaan') {
            $b = DB::table('pembayaran')->selectRaw(
                "id, 'Pengeluaran' AS tipe, code, date, name, category, amount, method, "
                . "'manual' AS source, status, payee_name AS counterparty, reference, description, vihara_id, bank_account_id, "
                . 'source_account_code AS cash_account_code, '
                . 'CASE WHEN attachment_path IS NULL THEN 0 ELSE 1 END AS has_attachment, '
                . 'voided_at, void_reason, submitted_at, rejected_at, reject_reason, submitted_by_user_id',
            );
            if ($sumber === 'km') {
                // Semua pengeluaran adalah manual — filter km mengosongkan branch ini.
                $b->whereRaw('1 = 0');
            }
            $branches[] = $this->applyCommonFilters($b, $request, $viharaIds, 'payee_name');
        }

        $union = array_shift($branches);
        foreach ($branches as $branch) {
            $union->unionAll($branch);
        }

        return DB::query()->fromSub($union, 't');
    }

    private function applyCommonFilters(Builder $q, Request $request, ?array $viharaIds, string $counterpartyColumn): Builder
    {
        // Yang dibatalkan IKUT tampil. Dulu disembunyikan, tapi sejak ada
        // pembatalan lewat jurnal pembalik, baris batal justru harus terlihat:
        // kalau hilang, pengguna tidak punya cara melihat apa yang dibatalkan
        // dan auditor kehilangan jejaknya. UI menandainya "Dibatalkan".
        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        } else {
            $q->whereIn('status', ['draft', 'submitted', 'posted', 'rejected', 'cancelled']);
        }

        if ($viharaIds !== null) {
            $q->whereIn('vihara_id', $viharaIds);
        }

        if ($pengaju = $request->integer('submitted_by')) {
            $q->where('submitted_by_user_id', $pengaju);
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

    /**
     * Sejak draft boleh belum punya kategori (lihat migrasi siklus draft),
     * nilainya bisa null — jangan sampai satu draft setengah jadi membuat
     * seluruh daftar transaksi gagal dimuat.
     */
    private function categoryLabel(?string $category): string
    {
        if ($category === null || $category === '') {
            return '—';
        }

        if (isset(self::PENERIMAAN_LABELS[$category])) {
            return self::PENERIMAAN_LABELS[$category];
        }
        if ($label = \App\Models\TransactionCategory::labelPengeluaran($category)) {
            return $label;
        }

        // Kategori dana buatan admin (FV-45) hanya ada di master.
        if ($label = \App\Models\TransactionCategory::labelPenerimaan($category)) {
            return $label;
        }

        return ucwords(str_replace('_', ' ', $category));
    }
}
