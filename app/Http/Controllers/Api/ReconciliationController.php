<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankStatementLine;
use App\Models\KmSettlement;
use App\Models\Penerimaan;
use App\Models\ReconciliationRun;
use App\Models\ViharaBankAccount;
use App\Services\BankReconMatchingService;
use App\Services\BankStatementImportService;
use App\Services\ViharaScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rekonsiliasi — 3 area sesuai mockup:
 * 1. Rekonsiliasi Bank per rekening fisik (run per rekening per bulan,
 *    statement lines manual/import, auto-matcher, complete).
 * 2. PG Clearing 1160 (read-only; settlement per-donasi real dari km_donations
 *    / km_settlements — webhook KM Finance, lihat KmWebhookController).
 * 3. Suspense 2310 (daftar donasi belum teridentifikasi; reklasifikasi ada
 *    di PenerimaanController::reklasifikasi).
 */
class ReconciliationController extends Controller
{
    // ------------------------------------------------------------------
    // 1. Rekonsiliasi Bank
    // ------------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $viharaId = ViharaScopeService::readFilter($request);

        $runs = ReconciliationRun::query()
            ->with('bankAccount:id,bank_name,account_number')
            ->withCount([
                'bankLines as statement_count',
                'bankLines as matched_count' => fn ($q) => $q->whereNotNull('matched_type'),
            ])
            ->when($viharaId, fn ($q) => $q->where('vihara_id', $viharaId))
            ->orderByDesc('period_month')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ReconciliationRun $run) => [
                'id' => $run->id,
                'code' => $run->code,
                'bank_account_id' => $run->bank_account_id,
                'bank_label' => $run->bankAccount?->label(),
                'period_month' => $run->period_month,
                'status' => $run->status,
                'statement_count' => (int) $run->statement_count,
                'matched_count' => (int) $run->matched_count,
                'system_count' => count(BankReconMatchingService::systemRows($run)),
                'bank_closing_balance' => $run->bank_closing_balance,
                'completed_at' => $run->completed_at?->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'runs' => $runs,
            'banks' => $this->bankSummaries($viharaId),
        ]);
    }

    /** Saldo buku per rekening (rumus sama dengan /api/kas-bank) + run terakhir. */
    private function bankSummaries(?int $viharaId): array
    {
        $accounts = ViharaBankAccount::query()
            ->where('active', true)
            ->when($viharaId, fn ($q) => $q->where('vihara_id', $viharaId))
            ->orderBy('bank_name')
            ->orderBy('id')
            ->get();

        $in = DB::table('penerimaan')->where('status', 'posted')->whereNotNull('bank_account_id')
            ->groupBy('bank_account_id')->selectRaw('bank_account_id, SUM(amount) AS s')
            ->pluck('s', 'bank_account_id');
        $out = DB::table('pembayaran')->where('status', 'posted')->whereNotNull('bank_account_id')
            ->groupBy('bank_account_id')->selectRaw('bank_account_id, SUM(amount) AS s')
            ->pluck('s', 'bank_account_id');
        $topup = DB::table('petty_cash_movements')->where('status', 'posted')
            ->where('subtype', 'topup')->whereNotNull('source_bank_account_id')
            ->groupBy('source_bank_account_id')->selectRaw('source_bank_account_id, SUM(amount) AS s')
            ->pluck('s', 'source_bank_account_id');

        $lastRuns = ReconciliationRun::query()
            ->whereIn('bank_account_id', $accounts->pluck('id'))
            ->orderBy('period_month')->orderBy('id')
            ->get(['id', 'bank_account_id', 'period_month', 'status'])
            ->groupBy('bank_account_id');

        return $accounts->map(function (ViharaBankAccount $a) use ($in, $out, $topup, $lastRuns) {
            $saldo = (float) ($in[$a->id] ?? 0) - (float) ($out[$a->id] ?? 0) - (float) ($topup[$a->id] ?? 0);
            $runs = $lastRuns->get($a->id);
            $last = $runs?->last();

            return [
                'bank_account_id' => $a->id,
                'bank_label' => $a->label(),
                'saldo_buku' => number_format($saldo, 2, '.', ''),
                'last_completed_month' => $runs?->where('status', 'completed')->max('period_month'),
                'last_run_id' => $last?->id,
                'last_run_status' => $last?->status,
            ];
        })->values()->all();
    }

    /** Bikin run baru: satu rekening fisik × satu bulan. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bank_account_id' => ['required', 'integer', 'exists:vihara_bank_accounts,id'],
            'period_month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'notes' => ['nullable', 'string'],
        ]);

        $bank = ViharaBankAccount::findOrFail($data['bank_account_id']);
        ViharaScopeService::guardRecord($request, $bank->vihara_id);

        $existing = ReconciliationRun::query()
            ->where('bank_account_id', $bank->id)
            ->where('period_month', $data['period_month'])
            ->first();
        if ($existing !== null) {
            return response()->json([
                'message' => "Rekonsiliasi {$bank->label()} periode {$data['period_month']} sudah pernah dibuat.",
                'run_id' => $existing->id,
            ], 422);
        }

        $start = Carbon::parse($data['period_month'] . '-01')->startOfMonth();

        $run = ReconciliationRun::create([
            'code' => $this->nextCode($data['period_month']),
            'vihara_id' => $bank->vihara_id,
            'bank_account_id' => $bank->id,
            'account_code' => $bank->coa_account_code,
            'period_month' => $data['period_month'],
            'period_start' => $start->toDateString(),
            'period_end' => (clone $start)->endOfMonth()->toDateString(),
            'status' => 'in_progress',
            'started_by' => $request->user()?->id,
            'started_at' => now(),
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json($this->runArray($run->fresh()->load('bankAccount')), 201);
    }

    public function show(Request $request, ReconciliationRun $reconciliation): JsonResponse
    {
        ViharaScopeService::guardRecord($request, $reconciliation->vihara_id);

        $lines = $reconciliation->bankLines()->orderBy('date')->orderBy('id')->get();
        $systemRows = BankReconMatchingService::systemRows($reconciliation);
        $preview = BankReconMatchingService::preview($reconciliation, $systemRows);

        $sistemMasuk = array_sum(array_map(
            fn ($r) => $r['direction'] === 'masuk' ? (float) $r['amount'] : 0,
            $systemRows,
        ));
        $sistemKeluar = array_sum(array_map(
            fn ($r) => $r['direction'] === 'keluar' ? (float) $r['amount'] : 0,
            $systemRows,
        ));

        return response()->json([
            'run' => $this->runArray($reconciliation->load('bankAccount')),
            'statement_lines' => $lines->map(fn ($l) => BankReconMatchingService::lineArray($l))->values(),
            'system_rows' => $systemRows,
            'preview' => $preview,
            'totals' => [
                'koran_masuk' => number_format((float) $lines->sum('debit'), 2, '.', ''),
                'koran_keluar' => number_format((float) $lines->sum('credit'), 2, '.', ''),
                'sistem_masuk' => number_format($sistemMasuk, 2, '.', ''),
                'sistem_keluar' => number_format($sistemKeluar, 2, '.', ''),
            ],
        ]);
    }

    /** Tambah baris koran manual. */
    public function storeLine(Request $request, ReconciliationRun $reconciliation): JsonResponse
    {
        ViharaScopeService::guardRecord($request, $reconciliation->vihara_id);
        $this->assertInProgress($reconciliation);

        $data = $request->validate([
            'date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:500'],
            'direction' => ['required', 'in:masuk,keluar'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $date = Carbon::parse($data['date'])->toDateString();
        if ($date < $reconciliation->period_start->toDateString()
            || $date > $reconciliation->period_end->toDateString()) {
            return response()->json([
                'message' => "Tanggal di luar periode rekonsiliasi ({$reconciliation->period_month}).",
            ], 422);
        }

        $debit = $data['direction'] === 'masuk' ? (float) $data['amount'] : 0.0;
        $credit = $data['direction'] === 'keluar' ? (float) $data['amount'] : 0.0;
        $signature = BankStatementLine::makeSignature($date, $data['description'], $debit, $credit);

        if ($reconciliation->bankLines()->where('signature', $signature)->exists()) {
            return response()->json(['message' => 'Item duplikat'], 422);
        }

        $line = BankStatementLine::create([
            'reconciliation_run_id' => $reconciliation->id,
            'date' => $date,
            'description' => $data['description'],
            'debit' => $debit,
            'credit' => $credit,
            'status' => 'unmatched',
            'source' => 'manual',
            'signature' => $signature,
        ]);

        return response()->json(BankReconMatchingService::lineArray($line), 201);
    }

    /** Hapus baris koran (hanya yang belum matched). */
    public function destroyLine(Request $request, ReconciliationRun $reconciliation, BankStatementLine $line): JsonResponse
    {
        ViharaScopeService::guardRecord($request, $reconciliation->vihara_id);
        $this->assertInProgress($reconciliation);
        $this->assertLineInRun($reconciliation, $line);

        if ($line->matched_type !== null) {
            return response()->json(['message' => 'Baris sudah matched — unmatch dulu sebelum menghapus.'], 422);
        }
        $line->delete();

        return response()->json(['message' => 'deleted']);
    }

    /** Import CSV/XLSX rekening koran (deteksi format dari isi file). */
    public function import(Request $request, ReconciliationRun $reconciliation): JsonResponse
    {
        ViharaScopeService::guardRecord($request, $reconciliation->vihara_id);
        $this->assertInProgress($reconciliation);

        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        try {
            $items = BankStatementImportService::parse($request->file('file'));
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'File tidak bisa dibaca. Gunakan CSV/XLSX rekening koran (BCA/Mandiri) atau format generic date,description,debit,credit.',
            ], 422);
        }
        if ($items === []) {
            return response()->json([
                'message' => 'Tidak ada baris mutasi yang dikenali dalam file. Pastikan ada baris header (Tanggal/Keterangan/...).',
            ], 422);
        }

        $imported = 0;
        $skippedDuplicates = 0;
        $skippedOutOfPeriod = 0;

        DB::transaction(function () use ($reconciliation, $items, &$imported, &$skippedDuplicates, &$skippedOutOfPeriod) {
            $start = $reconciliation->period_start->toDateString();
            $end = $reconciliation->period_end->toDateString();
            $existing = $reconciliation->bankLines()->lockForUpdate()
                ->whereNotNull('signature')->pluck('signature')
                ->flip()->all();

            foreach ($items as $item) {
                if ($item['masuk'] == 0.0 && $item['keluar'] == 0.0) {
                    continue; // nominal 0 — skip
                }
                if ($item['date'] < $start || $item['date'] > $end) {
                    $skippedOutOfPeriod++;
                    continue;
                }
                $signature = BankStatementLine::makeSignature(
                    $item['date'], $item['description'], $item['masuk'], $item['keluar'],
                );
                if (isset($existing[$signature])) {
                    $skippedDuplicates++;
                    continue;
                }
                $existing[$signature] = true;

                BankStatementLine::create([
                    'reconciliation_run_id' => $reconciliation->id,
                    'date' => $item['date'],
                    'description' => $item['description'],
                    'debit' => $item['masuk'],
                    'credit' => $item['keluar'],
                    'status' => 'unmatched',
                    'source' => 'import',
                    'signature' => $signature,
                ]);
                $imported++;
            }
        });

        return response()->json([
            'imported' => $imported,
            'skipped_duplicates' => $skippedDuplicates,
            'skipped_out_of_period' => $skippedOutOfPeriod,
        ]);
    }

    /** Match manual: baris koran ↔ dokumen sistem. */
    public function match(Request $request, ReconciliationRun $reconciliation): JsonResponse
    {
        ViharaScopeService::guardRecord($request, $reconciliation->vihara_id);
        $this->assertInProgress($reconciliation);

        $data = $request->validate([
            'line_id' => ['required', 'integer'],
            'matched_type' => ['required', 'in:' . implode(',', BankStatementLine::MATCHED_TYPES)],
            'matched_id' => ['required', 'integer'],
        ]);

        $line = $reconciliation->bankLines()->findOrFail($data['line_id']);
        if ($line->matched_type !== null) {
            return response()->json(['message' => 'Baris koran ini sudah matched.'], 422);
        }

        $row = BankReconMatchingService::findSystemRow($reconciliation, $data['matched_type'], $data['matched_id']);
        if ($row === null) {
            return response()->json([
                'message' => 'Dokumen sistem tidak ditemukan di rekening/periode run ini (harus posted, lewat rekening yang sama, dalam periode).',
            ], 422);
        }
        if ($row['matched']) {
            return response()->json(['message' => 'Dokumen sistem tersebut sudah matched ke baris koran lain.'], 422);
        }

        $lineDirection = $line->debit > 0 ? 'masuk' : 'keluar';
        if ($lineDirection !== $row['direction']) {
            return response()->json([
                'message' => 'Arah dana tidak sesuai (uang masuk hanya bisa di-match ke penerimaan, uang keluar ke pengeluaran).',
            ], 422);
        }

        $line->update([
            'matched_type' => $data['matched_type'],
            'matched_id' => $data['matched_id'],
            'status' => 'matched',
        ]);

        return response()->json(['message' => 'matched', 'line' => BankReconMatchingService::lineArray($line->fresh())]);
    }

    public function unmatch(Request $request, ReconciliationRun $reconciliation): JsonResponse
    {
        ViharaScopeService::guardRecord($request, $reconciliation->vihara_id);
        $this->assertInProgress($reconciliation);

        $data = $request->validate(['line_id' => ['required', 'integer']]);
        $line = $reconciliation->bankLines()->findOrFail($data['line_id']);

        $line->update([
            'matched_type' => null,
            'matched_id' => null,
            'matched_journal_line_id' => null,
            'status' => 'unmatched',
        ]);

        return response()->json(['message' => 'unmatched']);
    }

    /** Persist preview auto-matcher saat ini. Idempotent. */
    public function applyMatches(Request $request, ReconciliationRun $reconciliation): JsonResponse
    {
        ViharaScopeService::guardRecord($request, $reconciliation->vihara_id);
        $this->assertInProgress($reconciliation);

        return response()->json([
            'applied' => BankReconMatchingService::applyMatches($reconciliation),
        ]);
    }

    /** Top-3 kandidat sistem untuk satu baris koran. */
    public function suggestions(Request $request, ReconciliationRun $reconciliation): JsonResponse
    {
        ViharaScopeService::guardRecord($request, $reconciliation->vihara_id);

        $data = $request->validate(['line_id' => ['required', 'integer']]);
        $line = $reconciliation->bankLines()->findOrFail($data['line_id']);

        return response()->json([
            'candidates' => BankReconMatchingService::suggestions($reconciliation, $line),
        ]);
    }

    /**
     * Selesaikan run. Boleh dengan item unmatched (tetap terlihat di detail).
     * system_balance = mutasi sistem masuk − keluar selama periode.
     */
    public function complete(Request $request, ReconciliationRun $reconciliation): JsonResponse
    {
        ViharaScopeService::guardRecord($request, $reconciliation->vihara_id);
        $this->assertInProgress($reconciliation);

        $data = $request->validate([
            'bank_closing_balance' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string'],
        ]);

        $systemRows = BankReconMatchingService::systemRows($reconciliation);
        $systemBalance = array_sum(array_map(
            fn ($r) => ($r['direction'] === 'masuk' ? 1 : -1) * (float) $r['amount'],
            $systemRows,
        ));

        $reconciliation->update([
            'status' => 'completed',
            'system_balance' => $systemBalance,
            'bank_closing_balance' => $data['bank_closing_balance'] ?? $reconciliation->bank_closing_balance,
            'adjusted_balance' => $data['bank_closing_balance'] ?? $reconciliation->bank_closing_balance,
            'notes' => $data['notes'] ?? $reconciliation->notes,
            'completed_by' => $request->user()?->id,
            'completed_at' => now(),
        ]);

        return response()->json($this->runArray($reconciliation->fresh()->load('bankAccount')));
    }

    public function destroy(Request $request, ReconciliationRun $reconciliation): JsonResponse
    {
        ViharaScopeService::guardRecord($request, $reconciliation->vihara_id);

        if ($reconciliation->status !== 'in_progress') {
            return response()->json(['message' => 'Hanya run in_progress yang bisa dihapus.'], 422);
        }
        $reconciliation->delete();

        return response()->json(['message' => 'deleted']);
    }

    // ------------------------------------------------------------------
    // 2. PG Clearing (1160) — read-only
    // ------------------------------------------------------------------

    public function pgClearing(Request $request): JsonResponse
    {
        $request->validate([
            'mode' => ['sometimes', 'in:ytd,bulan'],
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'month' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'vihara_id' => ['sometimes', 'integer', 'exists:viharas,id'],
        ]);

        $viharaId = ViharaScopeService::readFilter($request);
        $mode = $request->string('mode')->toString() ?: 'ytd';
        $year = $request->integer('year') ?: now()->year;
        $month = $request->integer('month') ?: now()->month;

        if ($mode === 'bulan') {
            $start = Carbon::create($year, $month, 1)->startOfMonth();
            $end = (clone $start)->endOfMonth();
        } else {
            $start = Carbon::create($year, 1, 1);
            $end = $year < now()->year ? Carbon::create($year, 12, 31) : now();
        }
        [$startD, $endD] = [$start->toDateString(), $end->toDateString()];

        $scope = fn ($q) => $q->when($viharaId, fn ($qq) => $qq->where('vihara_id', $viharaId));

        $grossQ = $scope(DB::table('penerimaan'))
            ->where('status', 'posted')->where('method', 'auto_credit')
            ->whereBetween('date', [$startD, $endD]);
        $gross = (clone $grossQ)->selectRaw('COALESCE(SUM(amount),0) AS total, COUNT(*) AS n')->first();

        $komisi = $scope(DB::table('pembayaran'))
            ->where('status', 'posted')->where('category', 'komisi_platform')
            ->whereBetween('date', [$startD, $endD])
            ->selectRaw('COALESCE(SUM(amount),0) AS total, COUNT(*) AS n')->first();

        // Settled real — km_donations yang sudah dicairkan KM dalam periode
        // (by settled_at); total = jumlah gross donasi-donasinya.
        $settled = $scope(DB::table('km_donations'))
            ->where('status', 'settled')
            ->whereBetween('settled_at', ["{$startD} 00:00:00", "{$endD} 23:59:59"])
            ->selectRaw('COALESCE(SUM(amount),0) AS total, COUNT(*) AS n')->first();

        // Pending Escrow — penerimaan auto_credit periode ini yang belum
        // dicairkan: tanpa km_donation (data legacy/manual) atau km_donation
        // masih paid. Yang settled hilang dari daftar.
        $pending = Penerimaan::query()
            ->with('kmDonation:id,penerimaan_id,ref,status')
            ->when($viharaId, fn ($q) => $q->where('vihara_id', $viharaId))
            ->where('status', 'posted')->where('method', 'auto_credit')
            ->whereBetween('date', [$startD, $endD])
            ->whereDoesntHave('kmDonation', fn ($q) => $q->where('status', 'settled'))
            ->orderByDesc('date')->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (Penerimaan $p) => [
                'id' => $p->id,
                'date' => $p->date->toDateString(),
                'reference' => $p->reference,
                'donor' => $p->source_name,
                'name' => $p->name ?? $p->code,
                'category_label' => Penerimaan::CATEGORIES[$p->category]['label'] ?? $p->category,
                'amount' => $p->amount,
                'status' => $p->kmDonation?->status === 'settled' ? 'Settled' : 'Escrow',
                'km_ref' => $p->kmDonation?->ref,
            ])
            ->values();

        // Saldo 1160 sesungguhnya — jurnal posted akun 1160 (all time s/d
        // akhir periode, scoped vihara), bukan lagi gross − komisi.
        $saldo = (float) DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_code', '1160')
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.entry_date', '<=', $endD)
            ->when($viharaId, fn ($q) => $q->where('journal_entries.vihara_id', $viharaId))
            ->selectRaw('COALESCE(SUM(journal_lines.debit - journal_lines.credit),0) AS saldo')
            ->value('saldo');

        // Riwayat settlement KM dalam periode (terbaru dulu, cap 20).
        $settlements = KmSettlement::query()
            ->with('bankAccount:id,bank_name,account_number')
            ->withCount('donations')
            ->when($viharaId, fn ($q) => $q->where('vihara_id', $viharaId))
            ->whereBetween('settled_at', ["{$startD} 00:00:00", "{$endD} 23:59:59"])
            ->orderByDesc('settled_at')->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (KmSettlement $s) => [
                'id' => $s->id,
                'ref' => $s->ref,
                'date' => $s->settled_at->setTimezone(config('app.timezone'))->toDateString(),
                'gross' => $s->gross,
                'komisi' => $s->komisi,
                'net' => $s->net,
                'bank_label' => $s->bankAccount?->label(),
                'donation_count' => $s->donations_count,
            ])
            ->values();

        return response()->json([
            'period' => [
                'mode' => $mode,
                'year' => $year,
                'month' => $mode === 'bulan' ? $month : null,
                'start' => $startD,
                'end' => $endD,
            ],
            'gross' => ['total' => number_format((float) $gross->total, 2, '.', ''), 'count' => (int) $gross->n],
            'komisi' => ['total' => number_format((float) $komisi->total, 2, '.', ''), 'count' => (int) $komisi->n],
            'settled' => [
                'total' => number_format((float) $settled->total, 2, '.', ''),
                'count' => (int) $settled->n,
            ],
            'saldo_1160' => number_format($saldo, 2, '.', ''),
            'pending' => $pending,
            'settlements' => $settlements,
        ]);
    }

    // ------------------------------------------------------------------
    // 3. Suspense (2310)
    // ------------------------------------------------------------------

    public function suspense(Request $request): JsonResponse
    {
        $request->validate([
            'vihara_id' => ['sometimes', 'integer', 'exists:viharas,id'],
        ]);
        $viharaId = ViharaScopeService::readFilter($request);

        $items = Penerimaan::query()
            ->with('bankAccount:id,bank_name,account_number')
            ->when($viharaId, fn ($q) => $q->where('vihara_id', $viharaId))
            ->where('status', 'posted')
            ->where('category', 'donasi_belum_teridentifikasi')
            ->orderByDesc('date')->orderByDesc('id')
            ->get()
            ->map(fn (Penerimaan $p) => [
                'id' => $p->id,
                'date' => $p->date->toDateString(),
                'amount' => $p->amount,
                'reference' => $p->reference,
                'description' => $p->description,
                'bank_label' => $p->bankAccount?->label(),
                'method' => $p->method,
            ])
            ->values();

        return response()->json([
            'items' => $items,
            'total' => number_format((float) $items->sum(fn ($i) => (float) $i['amount']), 2, '.', ''),
            'count' => $items->count(),
        ]);
    }

    // ------------------------------------------------------------------

    private function runArray(ReconciliationRun $run): array
    {
        return [
            'id' => $run->id,
            'code' => $run->code,
            'vihara_id' => $run->vihara_id,
            'bank_account_id' => $run->bank_account_id,
            'bank_label' => $run->bankAccount?->label(),
            'account_code' => $run->account_code,
            'period_month' => $run->period_month,
            'period_start' => $run->period_start->toDateString(),
            'period_end' => $run->period_end->toDateString(),
            'status' => $run->status,
            'bank_opening_balance' => $run->bank_opening_balance,
            'bank_closing_balance' => $run->bank_closing_balance,
            'system_balance' => $run->system_balance,
            'notes' => $run->notes,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
        ];
    }

    private function assertInProgress(ReconciliationRun $run): void
    {
        if ($run->status !== 'in_progress') {
            abort(response()->json([
                'message' => "Run berstatus {$run->status} — sudah tidak bisa dimodifikasi.",
            ], 422));
        }
    }

    private function assertLineInRun(ReconciliationRun $run, BankStatementLine $line): void
    {
        if ((int) $line->reconciliation_run_id !== (int) $run->id) {
            abort(404, 'Baris koran tidak ditemukan pada run ini.');
        }
    }

    private function nextCode(string $periodMonth): string
    {
        $ym = str_replace('-', '', $periodMonth);
        $prefix = "REC-{$ym}";
        $latest = ReconciliationRun::where('code', 'like', "{$prefix}%")
            ->orderByDesc('code')->value('code');
        $next = $latest ? ((int) substr($latest, -3) + 1) : 1;

        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}
