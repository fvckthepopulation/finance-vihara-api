<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $entityId = $request->integer('entity_id');
        $asOf = $request->date('as_of_date') ?? now()->startOfDay();

        // Cash + Bank accounts (COA ISAK 35):
        //   1110 Kas, 1120 Kas Kecil, 1130/1140 Bank, 1160 PG Clearing
        $accounts = Account::query()
            ->where('active', true)
            ->where('is_header', false)
            ->where('code', 'like', '11%')
            ->orderBy('code')
            ->get(['code', 'name'])
            ->toArray();

        $journalQuery = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.entry_date', '<=', $asOf)
            ->groupBy('journal_lines.account_code')
            ->select(
                'journal_lines.account_code',
                DB::raw('SUM(debit) as total_debit'),
                DB::raw('SUM(credit) as total_credit'),
            );

        if ($entityId) {
            $journalQuery->where('journal_entries.entity_id', $entityId);
        }

        $totals = $journalQuery->get()->keyBy('account_code');

        $accountBalances = [];
        $grandTotal = 0;
        foreach ($accounts as $acc) {
            $tot = $totals->get($acc['code']);
            $balance = $tot ? (float) $tot->total_debit - (float) $tot->total_credit : 0;
            if (abs($balance) < 0.005) {
                continue;
            }
            $accountBalances[] = [
                'code' => $acc['code'],
                'name' => $acc['name'],
                'balance' => $balance,
            ];
            $grandTotal += $balance;
        }

        return response()->json([
            'as_of_date' => $asOf->toDateString(),
            'accounts' => $accountBalances,
            'totals' => [
                'cash_total' => $grandTotal,
                'net_position' => $grandTotal,
            ],
        ]);
    }

    public function transactions(Request $request, string $accountCode): JsonResponse
    {
        $account = Account::where('code', $accountCode)->firstOrFail();

        $start = $request->date('start_date') ?? now()->startOfMonth();
        $end = $request->date('end_date') ?? now()->endOfDay();
        $reconStatus = $request->string('recon_status')->toString();

        $opening = (float) JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_code', $accountCode)
            ->where('journal_entries.entry_date', '<', $start)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as balance')
            ->value('balance');

        $query = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_code', $accountCode)
            ->whereBetween('journal_entries.entry_date', [$start, $end])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_lines.id')
            ->select(
                'journal_lines.id',
                'journal_lines.debit',
                'journal_lines.credit',
                'journal_lines.description',
                'journal_lines.bank_statement_date',
                'journal_lines.bank_statement_ref',
                'journal_lines.reconciled_at',
                'journal_lines.reconciled_by_user_id',
                'journal_entries.id as entry_id',
                'journal_entries.entry_date',
                'journal_entries.reference_type',
                'journal_entries.reference_id',
                'journal_entries.description as entry_description',
            );

        if ($reconStatus === 'reconciled') {
            $query->whereNotNull('journal_lines.reconciled_at');
        } elseif ($reconStatus === 'unreconciled') {
            $query->whereNull('journal_lines.reconciled_at');
        }

        $rows = $query->get();

        $balance = $opening;
        $totalDebit = 0;
        $totalCredit = 0;
        $matched = 0;
        $running = [];
        foreach ($rows as $r) {
            $balance += (float) $r->debit - (float) $r->credit;
            $totalDebit += (float) $r->debit;
            $totalCredit += (float) $r->credit;
            if ($r->reconciled_at) {
                $matched++;
            }
            $running[] = [
                'id' => $r->id,
                'entry_id' => $r->entry_id,
                'entry_date' => $r->entry_date,
                'entry_description' => $r->entry_description,
                'reference_type' => $r->reference_type,
                'reference_id' => $r->reference_id,
                'debit' => (float) $r->debit,
                'credit' => (float) $r->credit,
                'description' => $r->description,
                'running_balance' => $balance,
                'reconciled_at' => $r->reconciled_at,
                'bank_statement_date' => $r->bank_statement_date,
                'bank_statement_ref' => $r->bank_statement_ref,
            ];
        }

        return response()->json([
            'account' => $account,
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'opening_balance' => $opening,
            'closing_balance' => $balance,
            'totals' => [
                'debit' => $totalDebit,
                'credit' => $totalCredit,
                'movement' => $totalDebit - $totalCredit,
                'count' => count($running),
                'reconciled_count' => $matched,
                'unreconciled_count' => count($running) - $matched,
            ],
            'rows' => $running,
        ]);
    }

    public function reconcile(Request $request, JournalLine $journalLine): JsonResponse
    {
        $data = $request->validate([
            'reconciled' => ['required', 'boolean'],
            'bank_statement_date' => ['sometimes', 'nullable', 'date'],
            'bank_statement_ref' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        if ($data['reconciled']) {
            $journalLine->update([
                'reconciled_at' => now(),
                'reconciled_by_user_id' => $request->user()->id,
                'bank_statement_date' => $data['bank_statement_date'] ?? $journalLine->bank_statement_date,
                'bank_statement_ref' => $data['bank_statement_ref'] ?? $journalLine->bank_statement_ref,
            ]);
        } else {
            $journalLine->update([
                'reconciled_at' => null,
                'reconciled_by_user_id' => null,
            ]);
        }

        return response()->json($journalLine->fresh());
    }
}
