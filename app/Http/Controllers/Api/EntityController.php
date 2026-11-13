<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD Yayasan — puncak hirarki tenant.
 *
 * Hanya admin tingkat global yang boleh melihat/mengubah lebih dari satu
 * yayasan. User tingkat yayasan hanya melihat yayasannya sendiri.
 */
class EntityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $q = Entity::query()
            ->withCount(['viharas', 'users'])
            ->withCount(['viharas as viharas_active_count' => fn ($w) => $w->where('active', true)])
            ->orderBy('code');

        if (! $user->isGlobalScope()) {
            $q->where('id', $user->entity_id);
        }
        if ($request->has('active')) {
            $q->where('active', $request->boolean('active'));
        }
        if ($request->boolean('active_only')) {
            $q->where('active', true);
        }
        if ($search = $request->string('q')->toString()) {
            $q->where(function ($w) use ($search) {
                $w->where('legal_name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('paginate')) {
            $perPage = min(max((int) $request->integer('per_page') ?: 20, 1), 100);

            return response()->json($q->paginate($perPage));
        }

        return response()->json(['data' => $q->get()]);
    }

    public function show(Request $request, Entity $entity): JsonResponse
    {
        $this->guard($request, $entity);

        return response()->json(
            $entity->loadCount('viharas')->load('viharas:id,entity_id,code,name,active'),
        );
    }

    public function store(Request $request): JsonResponse
    {
        // Menambah yayasan baru = menambah tenant, hak admin global saja.
        if (! $request->user()->isGlobalScope()) {
            abort(403, 'Hanya admin tingkat global yang boleh menambah yayasan.');
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:8', 'unique:entities,code'],
            'legal_name' => ['required', 'string', 'max:255'],
            'npwp' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);

        return response()->json(Entity::create($data)->loadCount('viharas'), 201);
    }

    public function update(Request $request, Entity $entity): JsonResponse
    {
        $this->guard($request, $entity);

        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:8', Rule::unique('entities', 'code')->ignore($entity->id)],
            'legal_name' => ['sometimes', 'string', 'max:255'],
            'npwp' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $entity->update($data);

        return response()->json($entity->fresh()->loadCount('viharas'));
    }

    public function destroy(Request $request, Entity $entity): JsonResponse
    {
        if (! $request->user()->isGlobalScope()) {
            abort(403, 'Hanya admin tingkat global yang boleh menghapus yayasan.');
        }
        if ($entity->viharas()->exists()) {
            return response()->json([
                'message' => 'Tidak bisa hapus — yayasan masih menaungi vihara. Nonaktifkan saja.',
            ], 422);
        }
        if ($entity->users()->exists()) {
            return response()->json([
                'message' => 'Tidak bisa hapus — masih ada pengguna terhubung.',
            ], 422);
        }

        $entity->delete();

        return response()->json(['message' => 'deleted']);
    }

    private function guard(Request $request, Entity $entity): void
    {
        $user = $request->user();
        if (! $user->isGlobalScope() && (int) $user->entity_id !== (int) $entity->id) {
            abort(403, 'Anda tidak memiliki akses ke yayasan tersebut.');
        }
    }
}
