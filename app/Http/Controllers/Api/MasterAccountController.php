<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * COA (Chart of Account) CMS — full CRUD for the master accounts table.
 * Used by the admin to manage accounts beyond the seeded baseline chart.
 */
class MasterAccountController extends Controller
{
    /**
     * GET /api/coa
     * List ALL accounts (including headers + inactive) with usage indicator.
     * Optional filters: q, type, active.
     */
    public function index(Request $request): JsonResponse
    {
        $q = Account::query()->orderBy('code');

        if ($type = $request->string('type')->toString()) {
            $q->where('type', $type);
        }
        if ($request->has('active')) {
            $q->where('active', $request->boolean('active'));
        }
        if ($search = $request->string('q')->toString()) {
            $q->where(function ($w) use ($search) {
                $w->where('code', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $mapRow = fn ($a, array $usage) => [
            'id' => $a->id,
            'code' => $a->code,
            'name' => $a->name,
            'type' => $a->type,
            'parent_code' => $a->parent_code,
            'entity_id' => $a->entity_id,
            'is_header' => (bool) $a->is_header,
            'note' => $a->note,
            'active' => (bool) $a->active,
            'usage_count' => (int) ($usage[$a->code] ?? 0),
            'created_at' => $a->created_at,
            'updated_at' => $a->updated_at,
        ];

        if ($request->boolean('paginate')) {
            $perPage = max(1, min(100, (int) $request->integer('per_page', 25)));
            $page = $q->paginate($perPage);
            $usage = $this->bulkUsage($page->getCollection()->pluck('code'));
            $page->getCollection()->transform(fn ($a) => $mapRow($a, $usage));
            return response()->json($page);
        }

        $accounts = $q->get();
        $usage = $this->bulkUsage($accounts->pluck('code'));
        return response()->json($accounts->map(fn ($a) => $mapRow($a, $usage)));
    }

    public function show(Account $account): JsonResponse
    {
        $usage = $this->bulkUsage(collect([$account->code]));
        return response()->json([
            'account' => $account,
            'usage_count' => (int) ($usage[$account->code] ?? 0),
            'usage_breakdown' => $this->usageBreakdown($account->code),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:10', 'unique:accounts,code', 'regex:/^\d{4}$/'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:asset,liability,equity,revenue,expense'],
            'parent_code' => ['nullable', 'string', 'exists:accounts,code'],
            'entity_id' => ['nullable', 'integer', 'exists:entities,id'],
            'is_header' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $account = Account::create($data + ['active' => $data['active'] ?? true]);

        return response()->json($account, 201);
    }

    public function update(Request $request, Account $account): JsonResponse
    {
        $data = $request->validate([
            // 'code' deliberately omitted — it's the FK referenced by journal_lines etc.
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'in:asset,liability,equity,revenue,expense'],
            'parent_code' => ['sometimes', 'nullable', 'string', 'exists:accounts,code'],
            'entity_id' => ['sometimes', 'nullable', 'integer', 'exists:entities,id'],
            'is_header' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'active' => ['sometimes', 'boolean'],
        ]);

        // Changing type/parent when account has been used can break downstream reports — warn caller
        $usage = $this->bulkUsage(collect([$account->code]));
        $isUsed = ((int) ($usage[$account->code] ?? 0)) > 0;
        if ($isUsed && ($request->has('type') || $request->has('parent_code'))) {
            if (! $request->boolean('force')) {
                return response()->json([
                    'message' => "Akun {$account->code} sudah dipakai di {$usage[$account->code]} jurnal/line. Ubah 'type' atau 'parent_code' bisa memengaruhi laporan keuangan. Tambahkan force=true untuk override.",
                ], 422);
            }
        }

        $account->update($data);

        return response()->json($account->fresh());
    }

    public function destroy(Account $account): JsonResponse
    {
        if ($account->is_system) {
            return response()->json([
                'message' => "Akun {$account->code} ({$account->name}) tidak bisa dihapus karena akun sistem (dipakai untuk auto-posting Modul 1/2). Nonaktifkan (set active=false) sebagai gantinya.",
            ], 422);
        }
        $usage = $this->bulkUsage(collect([$account->code]));
        if (((int) ($usage[$account->code] ?? 0)) > 0) {
            return response()->json([
                'message' => "Akun {$account->code} tidak bisa dihapus karena sudah dipakai di {$usage[$account->code]} jurnal/line. Nonaktifkan (set active=false) sebagai gantinya.",
            ], 422);
        }
        $account->delete();
        return response()->json(['message' => 'deleted']);
    }

    /**
     * Aggregate usage count per account_code across journal_lines.
     */
    private function bulkUsage($codes): array
    {
        if ($codes->isEmpty()) return [];

        $jl = DB::table('journal_lines')->whereIn('account_code', $codes)
            ->groupBy('account_code')->select('account_code', DB::raw('count(*) c'))
            ->get()->pluck('c', 'account_code')->toArray();

        $merged = [];
        foreach ($codes as $code) {
            $merged[$code] = $jl[$code] ?? 0;
        }
        return $merged;
    }

    private function usageBreakdown(string $code): array
    {
        return [
            'journal_lines' => JournalLine::where('account_code', $code)->count(),
        ];
    }
}
