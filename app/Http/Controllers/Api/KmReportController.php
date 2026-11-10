<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalLine;
use App\Services\ViharaScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vihara financial reports (COA ISAK 35, kode 4 digit).
 *
 * COA structure:
 *   1XXX Aset                    (debit balance)
 *   2XXX Liabilitas              (credit balance)
 *   3XXX Aset Neto               (credit balance)
 *   4XXX Pendapatan Dana         (credit balance) ← P&L
 *   5XXX Biaya Dana              (debit  balance) ← P&L
 *   7XXX Beban Komisi/Pajak      (debit  balance) ← P&L
 */
class KmReportController extends Controller
{
    /**
     * GET /api/reports/pl?from=YYYY-MM-DD&to=YYYY-MM-DD&status=posted|all
     * Default: month-to-date, posted only.
     */
    public function profitLoss(Request $request): JsonResponse
    {
        $this->resolveScope($request);

        [$from, $to, $status] = $this->parsePeriod($request);

        $revenue = $this->aggregate('4', $from, $to, $status, credit: true);
        $cogs    = $this->aggregate('5', $from, $to, $status, credit: false);
        $opex    = $this->aggregate('6', $from, $to, $status, credit: false);
        $tax     = $this->aggregate('7', $from, $to, $status, credit: false);

        $revenueTotal = $this->sumGroup($revenue);
        $cogsTotal    = $this->sumGroup($cogs);
        $opexTotal    = $this->sumGroup($opex);
        $taxTotal     = $this->sumGroup($tax);

        $grossProfit = $revenueTotal - $cogsTotal;
        $operatingProfit = $grossProfit - $opexTotal;
        $netProfit = $operatingProfit - $taxTotal;

        return response()->json([
            'period' => ['from' => $from, 'to' => $to, 'status' => $status],
            'revenue' => ['accounts' => $revenue, 'total' => $revenueTotal],
            'cogs' => ['accounts' => $cogs, 'total' => $cogsTotal],
            'gross_profit' => $grossProfit,
            'opex' => ['accounts' => $opex, 'total' => $opexTotal],
            'operating_profit' => $operatingProfit,
            'tax' => ['accounts' => $tax, 'total' => $taxTotal],
            'net_profit' => $netProfit,
        ]);
    }

    /**
     * GET /api/reports/balance-sheet?as_of=YYYY-MM-DD&status=posted|all
     * Default: today, posted only.
     */
    public function balanceSheet(Request $request): JsonResponse
    {
        $this->resolveScope($request);

        $asOf = $request->string('as_of')->toString() ?: now()->toDateString();
        $status = $request->string('status')->toString() ?: 'posted';

        $assets       = $this->aggregateAsOf('1', $asOf, $status, credit: false);
        $liabilities  = $this->aggregateAsOf('2', $asOf, $status, credit: true);
        $equity       = $this->aggregateAsOf('3', $asOf, $status, credit: true);

        // Earned surplus YTD bukan posted ke akun 3XXX otomatis (perlu closing entry).
        // Hitung ad-hoc: revenue − cogs − opex − tax dari awal tahun s/d asOf.
        $yearStart = substr($asOf, 0, 4) . '-01-01';
        $revenueYtd = $this->sumPrefix('4', $yearStart, $asOf, $status, credit: true);
        $cogsYtd    = $this->sumPrefix('5', $yearStart, $asOf, $status, credit: false);
        $opexYtd    = $this->sumPrefix('6', $yearStart, $asOf, $status, credit: false);
        $taxYtd     = $this->sumPrefix('7', $yearStart, $asOf, $status, credit: false);
        $retainedYtd = $revenueYtd - $cogsYtd - $opexYtd - $taxYtd;

        $assetsTotal      = $this->sumGroup($assets);
        $liabilitiesTotal = $this->sumGroup($liabilities);
        $equityBookTotal  = $this->sumGroup($equity);
        $equityTotal      = $equityBookTotal + $retainedYtd;

        return response()->json([
            'as_of' => $asOf,
            'status' => $status,
            'assets' => ['accounts' => $assets, 'total' => $assetsTotal],
            'liabilities' => ['accounts' => $liabilities, 'total' => $liabilitiesTotal],
            'equity' => [
                'accounts' => $equity,
                'book_total' => $equityBookTotal,
                'retained_earnings_ytd' => $retainedYtd,
                'total' => $equityTotal,
            ],
            'liabilities_plus_equity' => $liabilitiesTotal + $equityTotal,
            'balance_diff' => $assetsTotal - ($liabilitiesTotal + $equityTotal),
        ]);
    }

    /**
     * GET /api/reports/trial-balance?as_of=YYYY-MM-DD&status=posted|all
     */
    public function trialBalance(Request $request): JsonResponse
    {
        $this->resolveScope($request);

        $asOf = $request->string('as_of')->toString() ?: now()->toDateString();
        $status = $request->string('status')->toString() ?: 'posted';

        $q = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->tap(fn ($qq) => $this->scopeJournal($qq))
            ->whereDate('journal_entries.entry_date', '<=', $asOf);
        if ($status === 'posted') {
            $q->where('journal_entries.status', 'posted');
        }
        $rows = $q
            ->selectRaw('account_code, SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->groupBy('account_code')
            ->get();

        $accounts = Account::query()->orderBy('code')->get(['code', 'name', 'type'])->keyBy('code');

        $out = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($rows as $r) {
            $acc = $accounts[$r->account_code] ?? null;
            $debit = (float) $r->total_debit;
            $credit = (float) $r->total_credit;
            $bal = $debit - $credit;
            $out[] = [
                'code' => $r->account_code,
                'name' => $acc?->name,
                'type' => $acc?->type,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $bal,
            ];
            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        usort($out, fn ($a, $b) => strcmp($a['code'], $b['code']));

        return response()->json([
            'as_of' => $asOf,
            'status' => $status,
            'accounts' => $out,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'diff' => $totalDebit - $totalCredit,
        ]);
    }

    /**
     * GET /api/reports/pajak?from=YYYY-MM-DD&to=YYYY-MM-DD&status=posted|all
     *
     * Rekap:
     *   - Akun 7XXX (beban komisi platform KM / pajak)
     *   - Komisi platform KM yang dibayar (7140) — dasar rekonsiliasi dengan KM
     */
    public function pajakRekap(Request $request): JsonResponse
    {
        $this->resolveScope($request);

        [$from, $to, $status] = $this->parsePeriod($request);

        $pajak = $this->aggregate('7', $from, $to, $status, credit: false);

        $komisiCodes = ['7140'];
        $komisi = $this->aggregateCodes($komisiCodes, $from, $to, $status, credit: false);

        $monthly = $this->monthly7Series($from, $to, $status);

        return response()->json([
            'period' => ['from' => $from, 'to' => $to, 'status' => $status],
            'pajak' => ['accounts' => $pajak, 'total' => $this->sumGroup($pajak)],
            'komisi_km_dibayar' => ['accounts' => $komisi, 'total' => $this->sumGroup($komisi)],
            'pajak_monthly' => $monthly,
        ]);
    }

    // ────────────────────────── helpers ──────────────────────────

    /** @return array{0:string,1:string,2:string} [from, to, status] */
    /**
     * Batas vihara untuk seluruh query laporan pada request ini.
     * null = tanpa batas (scope global).
     *
     * @var array<int, int>|null
     */
    private ?array $viharaIds = null;

    /** Ikutkan jurnal level yayasan (vihara_id NULL). */
    private bool $includeYayasanLevel = true;

    private function resolveScope(Request $request): void
    {
        $this->viharaIds = ViharaScopeService::readFilterIds($request);
        $this->includeYayasanLevel = ! $request->filled('vihara_id')
            && $request->user()->canSeeYayasanLevel();
    }

    /** Terapkan batas vihara ke query jurnal apa pun di controller ini. */
    private function scopeJournal($q): void
    {
        if ($this->viharaIds === null) {
            return;
        }

        $ids = $this->viharaIds;
        $yys = $this->includeYayasanLevel;

        $q->where(function ($w) use ($ids, $yys) {
            $w->whereIn('journal_entries.vihara_id', $ids);
            if ($yys) {
                $w->orWhereNull('journal_entries.vihara_id');
            }
        });
    }
    private function parsePeriod(Request $request): array
    {
        $from = $request->string('from')->toString() ?: now()->startOfMonth()->toDateString();
        $to   = $request->string('to')->toString() ?: now()->toDateString();
        $status = $request->string('status')->toString() ?: 'posted';
        return [$from, $to, $status];
    }

    /**
     * Aggregate movements per akun untuk prefix tertentu, dalam period.
     * Return: array of ['code','name','total'] sorted by code.
     */
    private function aggregate(string $prefix, string $from, string $to, string $status, bool $credit): array
    {
        $q = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->tap(fn ($qq) => $this->scopeJournal($qq))
            ->where('journal_lines.account_code', 'like', "{$prefix}%")
            ->whereBetween('journal_entries.entry_date', [$from, $to]);
        if ($status === 'posted') {
            $q->where('journal_entries.status', 'posted');
        }
        $rows = $q
            ->selectRaw('account_code, SUM(debit) as td, SUM(credit) as tc')
            ->groupBy('account_code')
            ->get();

        $codes = $rows->pluck('account_code')->all();
        $accounts = Account::query()->whereIn('code', $codes)->get(['code', 'name'])->keyBy('code');

        $out = [];
        foreach ($rows as $r) {
            $val = $credit ? ((float) $r->tc - (float) $r->td) : ((float) $r->td - (float) $r->tc);
            if (abs($val) < 0.005) continue;
            $out[] = [
                'code' => $r->account_code,
                'name' => $accounts[$r->account_code]?->name ?? $r->account_code,
                'total' => $val,
            ];
        }
        usort($out, fn ($a, $b) => strcmp($a['code'], $b['code']));
        return $out;
    }

    private function aggregateCodes(array $codes, string $from, string $to, string $status, bool $credit): array
    {
        $q = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->tap(fn ($qq) => $this->scopeJournal($qq))
            ->whereIn('journal_lines.account_code', $codes)
            ->whereBetween('journal_entries.entry_date', [$from, $to]);
        if ($status === 'posted') {
            $q->where('journal_entries.status', 'posted');
        }
        $rows = $q
            ->selectRaw('account_code, SUM(debit) as td, SUM(credit) as tc')
            ->groupBy('account_code')
            ->get();

        $accounts = Account::query()->whereIn('code', $codes)->get(['code', 'name'])->keyBy('code');
        $out = [];
        foreach ($rows as $r) {
            $val = $credit ? ((float) $r->tc - (float) $r->td) : ((float) $r->td - (float) $r->tc);
            if (abs($val) < 0.005) continue;
            $out[] = [
                'code' => $r->account_code,
                'name' => $accounts[$r->account_code]?->name ?? $r->account_code,
                'total' => $val,
            ];
        }
        usort($out, fn ($a, $b) => strcmp($a['code'], $b['code']));
        return $out;
    }

    /** As-of snapshot per prefix (1XXX aset dst) — balance accumulation. */
    private function aggregateAsOf(string $prefix, string $asOf, string $status, bool $credit): array
    {
        $q = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->tap(fn ($qq) => $this->scopeJournal($qq))
            ->where('journal_lines.account_code', 'like', "{$prefix}%")
            ->whereDate('journal_entries.entry_date', '<=', $asOf);
        if ($status === 'posted') {
            $q->where('journal_entries.status', 'posted');
        }
        $rows = $q
            ->selectRaw('account_code, SUM(debit) as td, SUM(credit) as tc')
            ->groupBy('account_code')
            ->get();

        $codes = $rows->pluck('account_code')->all();
        $accounts = Account::query()->whereIn('code', $codes)->get(['code', 'name'])->keyBy('code');

        $out = [];
        foreach ($rows as $r) {
            $val = $credit ? ((float) $r->tc - (float) $r->td) : ((float) $r->td - (float) $r->tc);
            if (abs($val) < 0.005) continue;
            $out[] = [
                'code' => $r->account_code,
                'name' => $accounts[$r->account_code]?->name ?? $r->account_code,
                'total' => $val,
            ];
        }
        usort($out, fn ($a, $b) => strcmp($a['code'], $b['code']));
        return $out;
    }

    private function sumGroup(array $group): float
    {
        return array_sum(array_map(fn ($r) => (float) $r['total'], $group));
    }

    private function sumPrefix(string $prefix, string $from, string $to, string $status, bool $credit): float
    {
        $rows = $this->aggregate($prefix, $from, $to, $status, $credit);
        return $this->sumGroup($rows);
    }

    /** Beban 7XXX per bulan untuk chart. */
    private function monthly7Series(string $from, string $to, string $status): array
    {
        $q = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->tap(fn ($qq) => $this->scopeJournal($qq))
            ->where('journal_lines.account_code', 'like', '7%')
            ->whereBetween('journal_entries.entry_date', [$from, $to]);
        if ($status === 'posted') {
            $q->where('journal_entries.status', 'posted');
        }
        return $q
            ->selectRaw("to_char(journal_entries.entry_date, 'YYYY-MM') as month, SUM(debit - credit) as total")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($r) => ['month' => $r->month, 'total' => (float) $r->total])
            ->all();
    }
}
