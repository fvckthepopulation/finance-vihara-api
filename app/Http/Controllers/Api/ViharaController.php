<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vihara;
use App\Services\ViharaScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ViharaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Daftar vihara ikut hirarki: user vihara hanya melihat viharanya,
        // user yayasan melihat vihara di yayasannya, global melihat semua.
        $q = ViharaScopeService::visibleViharasQuery($request->user())
            ->with('entity:id,code,legal_name')
            ->orderBy('code');

        if ($entityId = $request->integer('entity_id')) {
            $q->where('entity_id', $entityId);
        }

        if ($request->boolean('active_only')) {
            $q->where('active', true);
        }
        if ($search = $request->string('q')->toString()) {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        return response()->json($q->paginate(min(max((int) $request->integer('per_page', 50), 1), 200)));
    }

    public function show(Request $request, Vihara $vihara): JsonResponse
    {
        ViharaScopeService::guardRecord($request, (int) $vihara->id);

        return response()->json($vihara->load('verifiedBy:id,name,email', 'entity:id,code,legal_name'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_id' => ['sometimes', 'integer', 'exists:entities,id'],
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

        // Vihara selalu lahir di bawah satu yayasan. User non-global hanya
        // boleh menambah vihara di yayasannya sendiri.
        $user = $request->user();
        if (! $user->isGlobalScope()) {
            if ($user->entity_id === null) {
                abort(403, 'Akun Anda tidak terikat ke yayasan mana pun.');
            }
            if (isset($data['entity_id']) && (int) $data['entity_id'] !== (int) $user->entity_id) {
                abort(403, 'Anda hanya boleh menambah vihara di yayasan Anda sendiri.');
            }
            $data['entity_id'] = (int) $user->entity_id;
        } elseif (! isset($data['entity_id'])) {
            abort(422, 'entity_id wajib diisi — pilih yayasan penaung vihara ini.');
        }

        $data['code'] = $this->nextCode();
        $vihara = Vihara::create($data);

        return response()->json($vihara->load('entity:id,code,legal_name'), 201);
    }

    public function update(Request $request, Vihara $vihara): JsonResponse
    {
        ViharaScopeService::guardRecord($request, (int) $vihara->id);

        $data = $request->validate([
            'entity_id' => ['sometimes', 'integer', 'exists:entities,id'],
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

        // Memindahkan vihara antar yayasan hanya hak admin tertinggi.
        if (isset($data['entity_id']) && ! $request->user()->isGlobalScope()
            && (int) $data['entity_id'] !== (int) $vihara->entity_id) {
            abort(403, 'Hanya admin tingkat global yang boleh memindahkan vihara antar yayasan.');
        }

        $vihara->update($data);

        return response()->json($vihara->fresh()->load('entity:id,code,legal_name'));
    }

    public function verify(Request $request, Vihara $vihara): JsonResponse
    {
        ViharaScopeService::guardRecord($request, (int) $vihara->id);

        if ($vihara->verified_at) {
            return response()->json(['message' => 'Vihara sudah diverifikasi.'], 422);
        }
        $vihara->update([
            'verified_at' => now(),
            'verified_by_user_id' => $request->user()->id,
        ]);
        return response()->json($vihara->fresh());
    }

    public function destroy(Request $request, Vihara $vihara): JsonResponse
    {
        ViharaScopeService::guardRecord($request, (int) $vihara->id);

        // Unit Umum/Pusat dibuat otomatis per yayasan dan tidak boleh dihapus
        // (P-01): transaksi tingkat yayasan bergantung padanya.
        if ($vihara->is_umum) {
            return response()->json([
                'message' => 'Unit Umum/Pusat Yayasan dibuat otomatis dan tidak bisa dihapus.',
            ], 422);
        }

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
