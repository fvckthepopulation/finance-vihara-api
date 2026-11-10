<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
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
