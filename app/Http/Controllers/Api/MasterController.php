<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Entity;
use App\Models\Payee;
use App\Models\TransactionName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MasterController extends Controller
{
    public function entities(): JsonResponse
    {
        return response()->json(
            Entity::query()->where('active', true)->orderBy('code')->get(['id', 'code', 'legal_name']),
        );
    }

    /**
     * Master Nama Penerimaan / Nama Pengeluaran untuk dropdown form input
     * transaksi. Wajib filter `type`, opsional `category`.
     */
    public function transactionNames(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['required', 'in:penerimaan,pengeluaran'],
            'category' => ['sometimes', 'string', 'max:100'],
        ]);

        $q = TransactionName::query()
            ->where('active', true)
            ->where('type', $request->string('type')->toString());

        if ($category = $request->string('category')->toString()) {
            $q->where('category', $category);
        }

        return response()->json([
            'data' => $q->orderBy('category')->orderBy('name')
                ->get(['id', 'type', 'category', 'name']),
        ]);
    }

    /**
     * Bank accounts lookup — virtual master derived from COA. Akun bank/PG
     * duduk di range 11xx (Aset — Kas & Bank). 1110 (Kas Kecil) dan 1115
     * (Kas Penerimaan Langsung) skip karena bukan rekening bank.
     */
    public function bankAccounts(Request $request): JsonResponse
    {
        $q = Account::query()
            ->where('active', true)
            ->where('is_header', false)
            ->where('code', 'like', '11%')
            ->whereNotIn('code', ['1110', '1115']);
        if ($search = $request->string('q')->toString()) {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }
        return response()->json(
            $q->with('entity:id,code,legal_name')
              ->orderBy('code')->limit(200)->get(['id', 'code', 'name', 'entity_id']),
        );
    }

    /**
     * Payee lookup for the Pembayaran "Dibayarkan kepada" dropdown. Filtered
     * by category (a payee with NULL categories is offered everywhere) and
     * optionally by type (supplier | provider).
     */
    public function payees(Request $request): JsonResponse
    {
        $q = Payee::query()->where('active', true)->orderBy('name');

        if ($category = $request->string('category')->toString()) {
            $q->where(function ($w) use ($category) {
                $w->whereNull('categories')
                  ->orWhereJsonContains('categories', $category);
            });
        }
        if ($type = $request->string('type')->toString()) {
            $q->where('type', $type);
        }
        if ($search = $request->string('q')->toString()) {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $q->limit(100)->get([
                'id', 'code', 'name', 'type', 'expense_account_code',
                'bank_name', 'bank_account_number', 'bank_account_holder', 'branch',
            ]),
        );
    }

    public function accounts(Request $request): JsonResponse
    {
        $q = Account::query()->where('active', true);
        // Default keeps headers out (postable accounts only); the manual
        // journal form needs them for its Akun(Header) → Uraian cascade.
        if (! $request->boolean('include_headers')) {
            $q->where('is_header', false);
        }
        if ($type = $request->string('type')->toString()) {
            $q->where('type', $type);
        }
        if ($search = $request->string('q')->toString()) {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }
        return response()->json(
            // Full chart of accounts is ~150 rows; the dropdowns load the whole
            // list, so the cap must cover it (a low cap dropped 6xx/7xx expense
            // accounts, making them unselectable). Still bounded as a safety net.
            $q->orderBy('code')->limit(500)->get(['id', 'code', 'name', 'type', 'parent_code', 'is_header']),
        );
    }
}
