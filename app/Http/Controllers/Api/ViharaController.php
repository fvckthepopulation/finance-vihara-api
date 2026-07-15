<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vihara;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ViharaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Vihara::query()->orderBy('code');

        if ($request->boolean('active_only')) {
            $q->where('active', true);
        }
        if ($search = $request->string('q')->toString()) {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        return response()->json($q->paginate(50));
    }

    public function show(Vihara $vihara): JsonResponse
    {
        return response()->json($vihara->load('verifiedBy:id,name,email'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string'],
            'contact_pic' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'bank_name' => ['required', 'string', 'max:64'],
            'bank_account_number' => ['required', 'string', 'max:32'],
            'bank_account_holder' => ['required', 'string', 'max:255'],
            'npwp' => ['sometimes', 'nullable', 'string', 'max:32'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $data['code'] = $this->nextCode();
        $vihara = Vihara::create($data);

        return response()->json($vihara, 201);
    }

    public function update(Request $request, Vihara $vihara): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string'],
            'contact_pic' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'bank_name' => ['sometimes', 'string', 'max:64'],
            'bank_account_number' => ['sometimes', 'string', 'max:32'],
            'bank_account_holder' => ['sometimes', 'string', 'max:255'],
            'npwp' => ['sometimes', 'nullable', 'string', 'max:32'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $vihara->update($data);

        return response()->json($vihara);
    }

    public function verify(Request $request, Vihara $vihara): JsonResponse
    {
        if ($vihara->verified_at) {
            return response()->json(['message' => 'Vihara sudah diverifikasi.'], 422);
        }
        $vihara->update([
            'verified_at' => now(),
            'verified_by_user_id' => $request->user()->id,
        ]);
        return response()->json($vihara->fresh());
    }

    public function destroy(Vihara $vihara): JsonResponse
    {
        if ($vihara->users()->exists()) {
            return response()->json(['message' => 'Tidak bisa hapus — masih ada user terhubung. Nonaktifkan saja.'], 422);
        }
        $vihara->delete();
        return response()->json(['message' => 'deleted']);
    }

    private function nextCode(): string
    {
        $last = Vihara::query()->where('code', 'like', 'V-%')->orderByDesc('id')->value('code');
        $n = $last ? ((int) substr($last, 2)) + 1 : 1;
        return 'V-' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }
}
