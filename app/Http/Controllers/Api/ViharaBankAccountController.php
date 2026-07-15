<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pembayaran;
use App\Models\Penerimaan;
use App\Models\PettyCashMovement;
use App\Models\Vihara;
use App\Models\ViharaBankAccount;
use App\Services\ViharaScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Master rekening bank per vihara. */
class ViharaBankAccountController extends Controller
{
    /** Dropdown master — rekening aktif, scoped per vihara user. */
    public function index(Request $request): JsonResponse
    {
        $q = ViharaBankAccount::query()
            ->where('active', true)
            ->orderBy('bank_name')
            ->orderBy('id');

        if ($viharaId = ViharaScopeService::readFilter($request)) {
            $q->where('vihara_id', $viharaId);
        }

        return response()->json([
            'data' => $q->get([
                'id', 'vihara_id', 'bank_name', 'account_number',
                'account_name', 'coa_account_code', 'active',
            ]),
        ]);
    }

    public function store(Request $request, Vihara $vihara): JsonResponse
    {
        $data = $request->validate([
            'bank_name' => ['required', 'string', 'max:64'],
            'account_number' => ['required', 'string', 'max:32'],
            'account_name' => ['required', 'string', 'max:255'],
            'coa_account_code' => ['sometimes', 'nullable', 'string', 'exists:accounts,code'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $account = $vihara->bankAccounts()->create($data + [
            'coa_account_code' => $data['coa_account_code'] ?? '1130',
        ]);

        return response()->json($account, 201);
    }

    public function update(Request $request, ViharaBankAccount $viharaBankAccount): JsonResponse
    {
        $data = $request->validate([
            'bank_name' => ['sometimes', 'string', 'max:64'],
            'account_number' => ['sometimes', 'string', 'max:32'],
            'account_name' => ['sometimes', 'string', 'max:255'],
            'coa_account_code' => ['sometimes', 'string', 'exists:accounts,code'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $viharaBankAccount->update($data);

        return response()->json($viharaBankAccount->fresh());
    }

    public function destroy(ViharaBankAccount $viharaBankAccount): JsonResponse
    {
        $referenced = Penerimaan::where('bank_account_id', $viharaBankAccount->id)->exists()
            || Pembayaran::where('bank_account_id', $viharaBankAccount->id)->exists()
            || PettyCashMovement::where('source_bank_account_id', $viharaBankAccount->id)->exists();

        if ($referenced) {
            return response()->json([
                'message' => 'Rekening sudah dipakai transaksi — tidak bisa dihapus. Nonaktifkan saja (active=false).',
            ], 422);
        }

        $viharaBankAccount->delete();

        return response()->json(['message' => 'deleted']);
    }
}
