<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vihara;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Http\Request;

/**
 * Penegak hirarki akses Yayasan -> Vihara. Satu-satunya tempat aturan ini
 * dirumuskan; controller hanya memanggil.
 *
 * Tiga tingkat (users.scope_type):
 *   global -> semua yayasan & semua vihara. Admin tertinggi.
 *   entity -> semua vihara di bawah users.entity_id, plus baris level
 *             yayasan (vihara_id NULL) seperti jurnal pusat & konsolidasi.
 *   vihara -> HANYA vihara di pivot user_vihara (bisa satu, bisa beberapa).
 *             Tidak pernah melihat baris level yayasan, dan tidak pernah
 *             melihat vihara lain.
 *
 * Isolasi antar vihara ditegakkan di query (whereIn), bukan hanya di UI.
 */
class ViharaScopeService
{
    /**
     * Terapkan batas akses user + filter opsional `?vihara_id=` ke sebuah
     * query. INI yang wajib dipakai endpoint read; jangan pakai hasil
     * readFilterIds() mentah untuk membangun where sendiri.
     *
     * @param  BuilderContract|\Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $column  Kolom vihara pada query (mis. 'je.vihara_id').
     * @param  bool  $includeYayasanLevel  Ikutkan baris vihara_id NULL bagi
     *                                     user yang berhak (jurnal pusat).
     * @param  string|null  $entityColumn  Kolom yayasan pada query; default
     *                                     diturunkan dari $column (…vihara_id → …entity_id).
     */
    public static function applyScope(
        $query,
        Request $request,
        string $column = 'vihara_id',
        bool $includeYayasanLevel = false,
        ?string $entityColumn = null,
    ): void {
        $user = $request->user();
        $requested = self::requestedViharaId($request);

        // Filter eksplisit: validasi dulu, baru dipakai.
        if ($requested !== null) {
            self::assertCanAccess($user, $requested);
            $query->where($column, $requested);

            return;
        }

        $allowed = $user->allowedViharaIds();

        // Scope global tanpa filter — tanpa batas.
        if ($allowed === null) {
            return;
        }

        $entityColumn ??= self::entityColumnFor($column);

        $query->where(function ($q) use ($column, $allowed, $includeYayasanLevel, $user, $entityColumn) {
            // whereIn([]) menghasilkan kondisi selalu-salah — itu memang
            // yang kita mau untuk user tanpa vihara ter-assign.
            $q->whereIn($column, $allowed);
            if ($includeYayasanLevel) {
                self::orYayasanLevel($q, $user, $column, $entityColumn);
            }
        });
    }

    /**
     * Tambahkan "OR baris tingkat yayasan milik yayasan user" ke sebuah
     * kondisi (FV-62). Baris tingkat yayasan = vihara kosong; tanpa syarat
     * yayasan, jurnal pusat yayasan LAIN ikut terbaca. User global melihat
     * semua yayasan; user vihara tidak pernah melihat baris tingkat yayasan.
     */
    public static function orYayasanLevel($query, User $user, string $viharaColumn, ?string $entityColumn): void
    {
        if (! $user->canSeeYayasanLevel()) {
            return;
        }

        $query->orWhere(function ($y) use ($user, $viharaColumn, $entityColumn) {
            $y->whereNull($viharaColumn);
            if (! $user->isGlobalScope()) {
                if ($entityColumn === null || $user->entity_id === null) {
                    // Tidak bisa memastikan yayasannya → jangan tampilkan.
                    $y->whereRaw('1 = 0');
                } else {
                    $y->where($entityColumn, $user->entity_id);
                }
            }
        });
    }

    /**
     * Yayasan yang barisnya boleh dibaca pada tingkat yayasan: id yayasan
     * user, atau null untuk user global (semua yayasan).
     */
    public static function yayasanLevelEntityId(User $user): ?int
    {
        return $user->isGlobalScope() ? null : ($user->entity_id !== null ? (int) $user->entity_id : -1);
    }

    /** 'je.vihara_id' → 'je.entity_id'; 'vihara_id' → 'entity_id'. */
    private static function entityColumnFor(string $viharaColumn): ?string
    {
        return str_ends_with($viharaColumn, 'vihara_id')
            ? substr($viharaColumn, 0, -strlen('vihara_id')).'entity_id'
            : null;
    }

    /**
     * Daftar id vihara yang boleh dibaca setelah memperhitungkan filter
     * `?vihara_id=`. null = tanpa batas (scope global, tanpa filter).
     *
     * Dipakai agregasi yang tidak bisa memakai applyScope (mis. menyusun
     * ringkasan per vihara di PHP).
     *
     * @return array<int, int>|null
     */
    public static function readFilterIds(Request $request): ?array
    {
        $user = $request->user();
        $requested = self::requestedViharaId($request);

        if ($requested !== null) {
            self::assertCanAccess($user, $requested);

            return [$requested];
        }

        return $user->allowedViharaIds();
    }

    /**
     * Satu vihara yang sedang difokuskan, bila ada: dari `?vihara_id=`, atau
     * karena user memang terkunci ke tepat satu vihara. null = lintas vihara
     * (pemanggil tetap WAJIB membatasi lewat applyScope/readFilterIds).
     */
    public static function focusedViharaId(Request $request): ?int
    {
        $ids = self::readFilterIds($request);

        return $ids !== null && count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * Tentukan vihara_id untuk record baru. User yang hanya punya satu
     * vihara tidak perlu mengirim apa-apa; selebihnya wajib memilih.
     */
    public static function creationViharaId(Request $request): int
    {
        $viharaId = self::optionalCreationViharaId($request);

        if ($viharaId === null) {
            $request->validate(['vihara_id' => ['required', 'integer', 'exists:viharas,id']]);
        }

        return $viharaId;
    }

    /**
     * Seperti creationViharaId, tapi null diperbolehkan bagi user yang
     * berhak atas data level yayasan (dipakai jurnal manual pusat).
     */
    public static function optionalCreationViharaId(Request $request): ?int
    {
        $user = $request->user();
        $requested = self::requestedViharaId($request);

        if ($requested !== null) {
            $request->validate(['vihara_id' => ['integer', 'exists:viharas,id']]);
            self::assertCanAccess($user, $requested);

            return $requested;
        }

        // Tidak dikirim: kalau user cuma punya satu vihara, pakai itu.
        $allowed = $user->allowedViharaIds();
        if ($allowed !== null && count($allowed) === 1) {
            return $allowed[0];
        }

        // Punya beberapa vihara tapi tidak memilih → ambigu, harus eksplisit.
        if ($allowed !== null && count($allowed) > 1 && ! $user->canSeeYayasanLevel()) {
            abort(422, 'vihara_id wajib dipilih — Anda memiliki akses ke lebih dari satu vihara.');
        }

        // Tidak punya vihara sama sekali dan bukan pemegang data yayasan.
        if ($allowed === [] && ! $user->canSeeYayasanLevel()) {
            abort(403, 'Akun Anda belum ditugaskan ke vihara mana pun.');
        }

        return null;
    }

    /** Guard akses satu record milik vihara tertentu (show/update/delete). */
    public static function guardRecord(Request $request, ?int $recordViharaId, ?int $recordEntityId = null): void
    {
        self::assertCanAccess($request->user(), $recordViharaId);

        // Baris tingkat yayasan: pastikan milik yayasan user (FV-62).
        if ($recordViharaId === null && $recordEntityId !== null) {
            self::guardEntity($request, $recordEntityId);
        }
    }

    /**
     * Guard yayasan yang disebut sebuah permintaan (FV-34).
     *
     * Jangkauan vihara dijaga ViharaScopeService di mana-mana, tapi
     * `entity_id` sempat hanya diperiksa ada-tidaknya di tabel. Akibatnya
     * jurnal manual bisa dicatat ke yayasan lain lewat API, walau dropdown-nya
     * tidak pernah menampilkan yayasan itu.
     */
    public static function guardEntity(Request $request, ?int $entityId): void
    {
        $user = $request->user();
        if ($entityId === null || $user->isGlobalScope()) {
            return;
        }
        if ((int) $entityId !== (int) $user->entity_id) {
            abort(403, 'Anda tidak memiliki akses ke yayasan tersebut.');
        }
    }

    /** Guard akses ke data level yayasan (konsolidasi, jurnal pusat). */
    public static function guardYayasanLevel(Request $request): void
    {
        if (! $request->user()->canSeeYayasanLevel()) {
            abort(403, 'Hanya pengguna tingkat yayasan yang boleh membuka data ini.');
        }
    }

    /**
     * Query vihara yang boleh dilihat user — dipakai master/dropdown agar
     * user vihara tidak bisa mengintip daftar vihara lain.
     */
    public static function visibleViharasQuery(User $user)
    {
        $q = Vihara::query();
        $allowed = $user->allowedViharaIds();

        if ($allowed !== null) {
            $q->whereIn('id', $allowed);
        }

        return $q;
    }

    private static function requestedViharaId(Request $request): ?int
    {
        return $request->filled('vihara_id') ? (int) $request->input('vihara_id') : null;
    }

    private static function assertCanAccess(User $user, ?int $viharaId): void
    {
        if (! $user->canAccessVihara($viharaId)) {
            abort(403, 'Anda tidak memiliki akses ke vihara tersebut.');
        }
    }
}
