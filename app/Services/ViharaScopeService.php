<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Multi-vihara scoping. Rule:
 * - User dengan `vihara_id` terisi hanya boleh melihat/membuat data untuk
 *   vihara-nya; minta vihara lain secara eksplisit → 403.
 * - User global (`vihara_id` null, mis. admin yayasan): create wajib kirim
 *   `vihara_id`; read boleh filter opsional `?vihara_id=` (absen = semua).
 */
class ViharaScopeService
{
    /**
     * Filter vihara untuk endpoint read (index/list/agregat).
     * Null = semua vihara (hanya mungkin untuk user global).
     */
    public static function readFilter(Request $request): ?int
    {
        $user = $request->user();
        $requested = $request->filled('vihara_id') ? (int) $request->input('vihara_id') : null;

        if ($user->vihara_id !== null) {
            if ($requested !== null && $requested !== (int) $user->vihara_id) {
                abort(403, 'Anda tidak memiliki akses ke vihara tersebut.');
            }
            return (int) $user->vihara_id;
        }

        return $requested;
    }

    /**
     * Tentukan vihara_id untuk record baru. User ter-scope dipaksa ke
     * vihara-nya; user global wajib mengirim vihara_id valid.
     */
    public static function creationViharaId(Request $request): int
    {
        $user = $request->user();
        $requested = $request->filled('vihara_id') ? (int) $request->input('vihara_id') : null;

        if ($user->vihara_id !== null) {
            if ($requested !== null && $requested !== (int) $user->vihara_id) {
                abort(403, 'Anda tidak memiliki akses ke vihara tersebut.');
            }
            return (int) $user->vihara_id;
        }

        $request->validate(['vihara_id' => ['required', 'integer', 'exists:viharas,id']]);

        return $requested;
    }

    /**
     * Seperti creationViharaId, tapi vihara_id opsional untuk user global:
     * null = record level yayasan (dipakai manual journal entry). User
     * ter-scope tetap dipaksa ke vihara-nya.
     */
    public static function optionalCreationViharaId(Request $request): ?int
    {
        $user = $request->user();
        $requested = $request->filled('vihara_id') ? (int) $request->input('vihara_id') : null;

        if ($user->vihara_id !== null) {
            if ($requested !== null && $requested !== (int) $user->vihara_id) {
                abort(403, 'Anda tidak memiliki akses ke vihara tersebut.');
            }
            return (int) $user->vihara_id;
        }

        if ($requested !== null) {
            $request->validate(['vihara_id' => ['integer', 'exists:viharas,id']]);
        }

        return $requested;
    }

    /** Guard akses satu record milik vihara tertentu (show/detail). */
    public static function guardRecord(Request $request, ?int $recordViharaId): void
    {
        $user = $request->user();
        if ($user->vihara_id !== null && (int) $user->vihara_id !== $recordViharaId) {
            abort(403, 'Anda tidak memiliki akses ke vihara tersebut.');
        }
    }
}
