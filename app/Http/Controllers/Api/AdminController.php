<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
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
     * GET /api/admin/users — list with roles.
     */
    public function users(Request $request): JsonResponse
    {
        $q = User::query()->with('roles:id,name', 'vihara:id,code,name')->orderBy('name');
        if ($search = $request->string('q')->toString()) {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }
        return response()->json($q->get(['id', 'name', 'email', 'entity_id', 'vihara_id', 'created_at']));
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
            'vihara_id' => ['nullable', 'integer', 'exists:viharas,id'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'entity_id' => $data['entity_id'] ?? null,
            'vihara_id' => $data['vihara_id'] ?? null,
            'email_verified_at' => now(),
        ]);
        $user->syncRoles([$data['role']]);

        return response()->json($user->fresh()->load('roles:id,name', 'vihara:id,code,name'), 201);
    }

    /**
     * PATCH /api/admin/users/{user}
     */
    public function updateUser(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'unique:users,email,' . $user->id],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'role' => ['sometimes', 'string', 'exists:roles,name'],
            'entity_id' => ['sometimes', 'nullable', 'integer', 'exists:entities,id'],
            'vihara_id' => ['sometimes', 'nullable', 'integer', 'exists:viharas,id'],
        ]);

        if (isset($data['password']) && $data['password']) {
            $user->password = Hash::make($data['password']);
        }
        if (isset($data['name'])) $user->name = $data['name'];
        if (isset($data['email'])) $user->email = $data['email'];
        if (array_key_exists('entity_id', $data)) $user->entity_id = $data['entity_id'];
        if (array_key_exists('vihara_id', $data)) $user->vihara_id = $data['vihara_id'];
        $user->save();

        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        return response()->json($user->fresh()->load('roles:id,name', 'vihara:id,code,name'));
    }

    /**
     * DELETE /api/admin/users/{user}
     */
    public function destroyUser(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Tidak bisa menghapus akun sendiri.'], 422);
        }
        if ($user->hasRole('superadmin') && User::role('superadmin')->count() <= 1) {
            return response()->json(['message' => 'Tidak bisa menghapus superadmin terakhir.'], 422);
        }
        $user->delete();
        return response()->json(['message' => 'deleted']);
    }

    /**
     * GET /api/admin/roles — list with permissions count + names
     */
    public function roles(): JsonResponse
    {
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
            ])
        );
    }

    /**
     * GET /api/admin/permissions — list all permission keys (catalog).
     */
    public function permissions(): JsonResponse
    {
        return response()->json([
            'permissions' => Permission::query()->orderBy('name')->pluck('name')->values(),
            'catalog' => RbacSeeder::PERMISSIONS,
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
}
