<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vihara;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AdminController extends Controller
{
    /**
     * GET /api/admin/users — list with roles + scope.
     *
     * Admin yayasan hanya melihat user di yayasannya; admin global melihat
     * semua.
     */
    public function users(Request $request): JsonResponse
    {
        $actor = $request->user();

        $q = User::query()
            ->with('roles:id,name', 'viharas:id,code,name', 'entity:id,code,legal_name')
            ->orderBy('name');

        if (! $actor->isGlobalScope()) {
            $q->where('entity_id', $actor->entity_id);
        }

        if ($search = $request->string('q')->toString()) {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $q->get(['id', 'name', 'email', 'entity_id', 'scope_type', 'created_at'])
                ->map(fn (User $u) => $this->userPayload($u)),
        );
    }

    /**
     * POST /api/admin/users
     */
    public function storeUser(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', 'exists:roles,name'],
            'entity_id' => ['nullable', 'integer', 'exists:entities,id'],
            'scope_type' => ['required', 'string', 'in:' . implode(',', User::SCOPE_TYPES)],
            'vihara_ids' => ['sometimes', 'array'],
            'vihara_ids.*' => ['integer', 'exists:viharas,id'],
            // Kompat klien lama: satu vihara.
            'vihara_id' => ['sometimes', 'nullable', 'integer', 'exists:viharas,id'],
        ]);

        $scope = $this->resolveScopeInput($request, $data);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'entity_id' => $scope['entity_id'],
            'scope_type' => $scope['scope_type'],
            'email_verified_at' => now(),
        ]);
        $user->syncRoles([$data['role']]);
        $user->viharas()->sync($scope['vihara_ids']);

        return response()->json($this->userPayload($user->fresh()), 201);
    }

    /**
     * PATCH /api/admin/users/{user}
     */
    public function updateUser(Request $request, User $user): JsonResponse
    {
        $this->assertManageable($request, $user);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'unique:users,email,' . $user->id],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'role' => ['sometimes', 'string', 'exists:roles,name'],
            'entity_id' => ['sometimes', 'nullable', 'integer', 'exists:entities,id'],
            'scope_type' => ['sometimes', 'string', 'in:' . implode(',', User::SCOPE_TYPES)],
            'vihara_ids' => ['sometimes', 'array'],
            'vihara_ids.*' => ['integer', 'exists:viharas,id'],
            'vihara_id' => ['sometimes', 'nullable', 'integer', 'exists:viharas,id'],
        ]);

        if (isset($data['password']) && $data['password']) {
            $user->password = Hash::make($data['password']);
        }
        if (isset($data['name'])) {
            $user->name = $data['name'];
        }
        if (isset($data['email'])) {
            $user->email = $data['email'];
        }

        $touchesScope = array_key_exists('scope_type', $data)
            || array_key_exists('entity_id', $data)
            || array_key_exists('vihara_ids', $data)
            || array_key_exists('vihara_id', $data);

        if ($touchesScope) {
            $scope = $this->resolveScopeInput($request, $data + [
                'scope_type' => $data['scope_type'] ?? $user->scope_type,
                'entity_id' => array_key_exists('entity_id', $data) ? $data['entity_id'] : $user->entity_id,
            ]);
            $user->entity_id = $scope['entity_id'];
            $user->scope_type = $scope['scope_type'];
        }

        $user->save();

        if ($touchesScope) {
            $user->viharas()->sync($scope['vihara_ids']);
        }

        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        return response()->json($this->userPayload($user->fresh()));
    }

    /**
     * DELETE /api/admin/users/{user}
     */
    public function destroyUser(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Tidak bisa menghapus akun sendiri.'], 422);
        }
        $this->assertManageable($request, $user);
        if ($user->hasRole('superadmin') && User::role('superadmin')->count() <= 1) {
            return response()->json(['message' => 'Tidak bisa menghapus superadmin terakhir.'], 422);
        }
        $user->delete();
        return response()->json(['message' => 'deleted']);
    }

    /**
     * Katalog role/permission boleh dibaca oleh pengelola pengguna (butuh
     * daftar role untuk form) maupun pengelola role. Selain itu ditolak.
     */
    private function assertCanReadRoleCatalog(Request $request): void
    {
        $user = $request->user();
        if (! $user->can('users.manage') && ! $user->can('roles.manage')) {
            abort(403, 'Anda tidak berhak membaca katalog role.');
        }
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        $user->loadMissing('roles:id,name', 'viharas:id,code,name', 'entity:id,code,legal_name');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'entity_id' => $user->entity_id,
            'entity' => $user->entity,
            'scope_type' => $user->scope_type,
            'viharas' => $user->viharas->map(fn ($v) => [
                'id' => $v->id, 'code' => $v->code, 'name' => $v->name,
            ])->values(),
            'vihara_ids' => $user->viharas->pluck('id')->values(),
            'vihara_id' => $user->primary_vihara_id,
            'roles' => $user->roles->pluck('name')->values(),
            'created_at' => $user->created_at,
        ];
    }

    /**
     * Admin yayasan tidak boleh menyentuh user di luar yayasannya.
     */
    private function assertManageable(Request $request, User $target): void
    {
        $actor = $request->user();

        if ($actor->isGlobalScope()) {
            return;
        }

        if ($target->isGlobalScope() || (int) $target->entity_id !== (int) $actor->entity_id) {
            abort(403, 'Anda hanya boleh mengelola pengguna di yayasan Anda sendiri.');
        }
    }

    /**
     * Normalisasi + validasi hak atas input scope.
     *
     * Admin non-global tidak boleh membuat user `global`, tidak boleh keluar
     * dari yayasannya, dan hanya boleh memberi vihara yang ia sendiri punya
     * aksesnya.
     *
     * @param  array<string, mixed>  $data
     * @return array{scope_type: string, entity_id: ?int, vihara_ids: array<int, int>}
     */
    private function resolveScopeInput(Request $request, array $data): array
    {
        $actor = $request->user();
        $scopeType = $data['scope_type'] ?? User::SCOPE_VIHARA;
        $entityId = $data['entity_id'] ?? null;

        // vihara_ids, dengan fallback ke vihara_id tunggal (klien lama).
        $viharaIds = array_values(array_unique(array_map(
            'intval',
            $data['vihara_ids'] ?? (isset($data['vihara_id']) && $data['vihara_id'] ? [$data['vihara_id']] : []),
        )));

        if (! $actor->isGlobalScope()) {
            if ($scopeType === User::SCOPE_GLOBAL) {
                abort(403, 'Hanya admin global yang boleh membuat akun berakses global.');
            }
            if ($actor->entity_id === null) {
                abort(403, 'Akun Anda tidak terikat ke yayasan mana pun.');
            }
            if ($entityId !== null && (int) $entityId !== (int) $actor->entity_id) {
                abort(403, 'Anda hanya boleh mengelola pengguna di yayasan Anda sendiri.');
            }
            $entityId = (int) $actor->entity_id;

            $allowed = $actor->allowedViharaIds() ?? [];
            foreach ($viharaIds as $id) {
                if (! in_array($id, $allowed, true)) {
                    abort(403, 'Anda tidak memiliki akses ke salah satu vihara yang dipilih.');
                }
            }
        }

        if ($scopeType === User::SCOPE_VIHARA && $viharaIds === []) {
            abort(422, 'Pilih minimal satu vihara untuk akses tingkat vihara.');
        }
        if ($scopeType === User::SCOPE_ENTITY && $entityId === null) {
            abort(422, 'Pilih yayasan untuk akses tingkat yayasan.');
        }

        // Vihara yang dipilih harus benar-benar milik yayasan user tsb.
        if ($scopeType === User::SCOPE_VIHARA && $entityId !== null && $viharaIds !== []) {
            $mismatch = Vihara::query()
                ->whereIn('id', $viharaIds)
                ->where(fn ($q) => $q->where('entity_id', '!=', $entityId)->orWhereNull('entity_id'))
                ->exists();
            if ($mismatch) {
                abort(422, 'Ada vihara yang bukan milik yayasan yang dipilih.');
            }
        }

        return [
            'scope_type' => $scopeType,
            'entity_id' => $entityId,
            // Pivot hanya relevan untuk scope vihara.
            'vihara_ids' => $scopeType === User::SCOPE_VIHARA ? $viharaIds : [],
        ];
    }

    /**
     * GET /api/admin/roles — list with permissions count + names
     */
    public function roles(Request $request): JsonResponse
    {
        $this->assertCanReadRoleCatalog($request);

        $roles = Role::query()->with('permissions:id,name')->orderBy('name')->get();

        // Count users per role via direct query (avoid Spatie morphedByMany config lookup quirk)
        $userCounts = \DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->groupBy('role_id')
            ->selectRaw('role_id, count(*) as c')
            ->pluck('c', 'role_id');

        return response()->json(
            $roles->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'guard_name' => $r->guard_name,
                'users_count' => (int) ($userCounts[$r->id] ?? 0),
                'permissions' => $r->permissions->pluck('name')->values(),
                'is_protected' => in_array($r->name, ['superadmin']),
                // Role bawaan RbacSeeder dibuat ulang setiap seeding, jadi
                // menghapusnya hanya menipu: ia kembali pada deploy berikutnya.
                'is_builtin' => isset(RbacSeeder::ROLES[$r->name]),
                // Satu-satunya sumber kebenaran untuk tombol hapus di UI;
                // penegakan sebenarnya tetap di destroyRole().
                'can_delete' => ! isset(RbacSeeder::ROLES[$r->name])
                    && (int) ($userCounts[$r->id] ?? 0) === 0,
                'description' => RbacSeeder::ROLES[$r->name]['description'] ?? null,
                // Anjuran untuk form tambah pengguna; penegakan tetap di
                // users.scope_type, bukan di role.
                'default_scope' => RbacSeeder::ROLES[$r->name]['default_scope'] ?? User::SCOPE_VIHARA,
            ])
        );
    }

    /**
     * GET /api/admin/permissions — list all permission keys (catalog).
     */
    public function permissions(Request $request): JsonResponse
    {
        $this->assertCanReadRoleCatalog($request);

        // Hak yang tidak mengatur apa pun disembunyikan dari matrix supaya
        // penyusun role tidak mengira sudah membatasi sesuatu — lihat
        // RbacSeeder::HIDDEN_PERMISSIONS.
        $tersembunyi = RbacSeeder::HIDDEN_PERMISSIONS;

        return response()->json([
            'permissions' => Permission::query()->orderBy('name')->pluck('name')
                ->reject(fn ($n) => in_array($n, $tersembunyi, true))->values(),
            'catalog' => array_values(array_diff(RbacSeeder::PERMISSIONS, $tersembunyi)),
            'descriptions' => RbacSeeder::PERMISSION_DESCRIPTIONS,
            // Dikirim apa adanya supaya UI bisa menjelaskan kalau sebuah role
            // lama masih memegangnya.
            'hidden' => $tersembunyi,
        ]);
    }

    /**
     * PATCH /api/admin/roles/{role} — replace permission set for a role
     */
    public function updateRole(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        // Don't allow shrinking superadmin away from full perms (it's the fail-safe)
        if ($role->name === 'superadmin') {
            return response()->json(['message' => 'Role superadmin tidak boleh dimodifikasi.'], 422);
        }

        $role->syncPermissions($data['permissions']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->fresh()->permissions->pluck('name')->values(),
        ]);
    }

    /**
     * POST /api/admin/roles — create a new custom role.
     */
    public function storeRole(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', 'unique:roles,name', 'regex:/^[a-z0-9_]+$/'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        if (! empty($data['permissions'] ?? [])) {
            $role->syncPermissions($data['permissions']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->values(),
        ], 201);
    }

    /**
     * DELETE /api/admin/roles/{role}
     *
     * Hanya role buatan sendiri yang boleh dihapus, dan hanya selama tidak
     * ada pengguna yang memakainya. Menghapus role yang masih dipakai akan
     * melepas seluruh permission penggunanya tanpa jejak — itu kegagalan
     * senyap, bukan kemudahan.
     */
    public function destroyRole(Request $request, Role $role): JsonResponse
    {
        if ($role->name === 'superadmin') {
            return response()->json(['message' => 'Role superadmin tidak boleh dihapus.'], 422);
        }

        if (isset(RbacSeeder::ROLES[$role->name])) {
            return response()->json([
                'message' => "Role bawaan '{$role->name}' tidak boleh dihapus — ia dibuat ulang setiap seeding. Kosongkan permission-nya bila ingin dinonaktifkan.",
            ], 422);
        }

        $dipakai = $role->users()->count();
        if ($dipakai > 0) {
            return response()->json([
                'message' => "Role '{$role->name}' masih dipakai {$dipakai} pengguna. Pindahkan pengguna itu ke role lain dulu.",
            ], 422);
        }

        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json(['message' => 'deleted']);
    }
}
