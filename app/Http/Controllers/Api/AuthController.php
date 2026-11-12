<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vihara;
use App\Models\Entity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah.'],
            ]);
        }

        $token = $user->createToken($data['device_name'] ?? 'web')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => self::profile($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(self::profile($request->user()));
    }

    /**
     * Profil + hirarki akses. Frontend memakai `scope_type` dan `vihara_ids`
     * untuk menentukan apa yang boleh ditampilkan; `vihara_id` dipertahankan
     * demi klien lama dan hanya terisi bila user terkunci ke satu vihara.
     *
     * @return array<string, mixed>
     */
    /**
     * GET /api/my-scope — yayasan & vihara yang boleh dipakai pengguna ini
     * untuk BERTRANSAKSI.
     *
     * Sengaja terpisah dari /api/entities dan /api/viharas. Kedua endpoint itu
     * adalah master data dan dijaga izin `entities.view` / `masters.view`;
     * banyak role operasional tidak memilikinya. Kalau form transaksi
     * bergantung ke sana, pengguna dengan role seperti itu mendapat dropdown
     * terkunci dan KOSONG tanpa penjelasan — tidak bisa bertransaksi sama
     * sekali, padahal jangkauan aksesnya jelas.
     *
     * Di sini tidak ada izin tambahan: yang dikembalikan adalah jangkauan
     * pengguna itu sendiri, bukan katalog master. Batasannya tetap sama
     * dengan ViharaScopeService.
     */
    public function scopeOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        $viharaIds = $user->allowedViharaIds();

        $viharaQuery = Vihara::query()->where('active', true)->orderBy('name');
        if ($viharaIds !== null) {
            $viharaQuery->whereIn('id', $viharaIds);
        }
        $viharas = $viharaQuery->get(['id', 'code', 'name', 'entity_id']);

        $entityQuery = Entity::query()->where('active', true)->orderBy('legal_name');
        if (! $user->isGlobalScope()) {
            // Yayasan sendiri, ditambah yayasan dari vihara yang ditugaskan —
            // keduanya biasanya sama, tapi tidak dijamin.
            $ids = array_values(array_unique(array_filter(
                array_merge([$user->entity_id], $viharas->pluck('entity_id')->all()),
            )));
            $entityQuery->whereIn('id', $ids ?: [0]);
        }

        return response()->json([
            'entities' => $entityQuery->get(['id', 'code', 'legal_name']),
            'viharas' => $viharas,
        ]);
    }

    private static function profile(User $user): array
    {
        $viharaIds = $user->allowedViharaIds();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'entity_id' => $user->entity_id,
            'entity' => $user->entity
                ? ['id' => $user->entity->id, 'code' => $user->entity->code, 'legal_name' => $user->entity->legal_name]
                : null,
            'scope_type' => $user->scope_type,
            // null = tanpa batas (admin tertinggi).
            'vihara_ids' => $viharaIds,
            'vihara_id' => $user->primary_vihara_id,
            'can_see_yayasan_level' => $user->canSeeYayasanLevel(),
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'app' => [
                // Mode yayasan tunggal: UI menyembunyikan pemilih vihara
                // dan menu konsolidasi.
                'single_entity_mode' => (bool) config('app.single_entity_mode'),
            ],
        ];
    }
}
