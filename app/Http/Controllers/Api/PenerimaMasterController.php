<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payee;
use App\Models\Pembayaran;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Master Penerima (FV-31).
 *
 * Field "Dibayarkan kepada / PIC" tetap boleh diisi bebas — itu syarat dari
 * issue-nya. Yang ditambahkan: saran dari master saat mengetik, dan jalan
 * singkat untuk memasukkan nama baru ke master tanpa meninggalkan form.
 *
 * Masternya milik YAYASAN (entity_id): vendor biasanya lokal. Baris dengan
 * entity_id NULL berlaku di semua yayasan.
 */
class PenerimaMasterController extends Controller
{
    public const JENIS = [
        'vendor' => 'Vendor',
        'perorangan' => 'Perorangan',
        'internal' => 'Internal',
    ];

    /**
     * Saran untuk form. Terbuka untuk siapa pun yang boleh mencatat
     * pengeluaran — kalau dijaga izin master, operator yang tidak punya izin
     * itu mendapat field tanpa saran sama sekali tanpa penjelasan.
     */
    public function suggest(Request $request): JsonResponse
    {
        $q = Payee::query()
            ->where('active', true)
            ->orderBy('name')
            ->limit(20);

        $this->batasiYayasan($q, $request);

        if ($search = $request->string('q')->toString()) {
            $like = '%'.$search.'%';
            $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like));
        }

        return response()->json([
            'data' => $q->get(['id', 'code', 'name', 'type', 'bank_name', 'bank_account_number']),
        ]);
    }

    /**
     * Tambah penerima dari dalam form: cukup nama. Kalau namanya sudah ada
     * (tanpa membedakan huruf besar/kecil), yang dikembalikan baris yang ada —
     * bukan baris kedua. Itu inti keluhannya.
     */
    public function quickStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(array_keys(self::JENIS))],
        ]);

        $entityId = $request->user()->entity_id;

        $ada = Payee::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])
            ->where(fn ($w) => $w->whereNull('entity_id')->orWhere('entity_id', $entityId))
            ->first();

        if ($ada !== null) {
            // Nonaktif lalu dipakai lagi → diaktifkan kembali, bukan diduplikasi.
            if (! $ada->active) {
                $ada->update(['active' => true]);
            }

            return response()->json($ada->fresh()->toArray() + ['sudah_ada' => true]);
        }

        $penerima = Payee::create([
            'name' => trim($data['name']),
            'type' => $data['type'] ?? 'vendor',
            'entity_id' => $entityId,
            'active' => true,
        ]);

        return response()->json($penerima, 201);
    }

    // ── Layar master ───────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        // Yayasan memakai kolom legal_name, bukan name.
        $q = Payee::query()->with('entity:id,legal_name')->orderBy('name');

        $this->batasiYayasan($q, $request);

        if ($search = $request->string('q')->toString()) {
            $like = '%'.$search.'%';
            $q->where(function ($w) use ($like) {
                $w->where('name', 'ilike', $like)
                    ->orWhere('code', 'ilike', $like)
                    ->orWhere('phone', 'ilike', $like)
                    ->orWhere('npwp', 'ilike', $like)
                    ->orWhere('bank_account_number', 'ilike', $like);
            });
        }
        if ($jenis = $request->string('type')->toString()) {
            $q->where('type', $jenis);
        }
        if ($request->has('active') && $request->string('active')->toString() !== '') {
            $q->where('active', $request->boolean('active'));
        }

        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);
        $halaman = $q->paginate($perPage);

        $halaman->getCollection()->transform(fn (Payee $p) => [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'type' => $p->type,
            'type_label' => self::JENIS[$p->type] ?? $p->type,
            'phone' => $p->phone,
            'address' => $p->address,
            'bank_name' => $p->bank_name,
            'bank_account_number' => $p->bank_account_number,
            'bank_account_holder' => $p->bank_account_holder,
            'npwp' => $p->npwp,
            'entity_id' => $p->entity_id,
            'entity_name' => $p->entity?->legal_name,
            'active' => (bool) $p->active,
            'in_use' => $this->dipakai($p),
        ]);

        return response()->json($halaman);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validasi($request, null);

        $penerima = Payee::create($data + [
            'entity_id' => $request->user()->entity_id,
            'active' => $data['active'] ?? true,
        ]);

        return response()->json($penerima, 201);
    }

    public function update(Request $request, Payee $penerima): JsonResponse
    {
        $this->guardYayasan($request, $penerima);

        $penerima->update($this->validasi($request, $penerima));

        return response()->json($penerima->fresh());
    }

    public function destroy(Request $request, Payee $penerima): JsonResponse
    {
        $this->guardYayasan($request, $penerima);

        if ($this->dipakai($penerima)) {
            return response()->json([
                'message' => 'Penerima sudah dipakai transaksi — tidak bisa dihapus. Nonaktifkan saja.',
            ], 422);
        }

        $penerima->delete();

        return response()->json(['message' => 'deleted']);
    }

    // ── Bantuan ────────────────────────────────────────────────────────────

    private function validasi(Request $request, ?Payee $existing): array
    {
        $baru = $existing === null;

        $data = $request->validate([
            'name' => [$baru ? 'required' : 'sometimes', 'string', 'max:255'],
            'type' => [$baru ? 'required' : 'sometimes', Rule::in(array_keys(self::JENIS))],
            'code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:64'],
            'bank_account_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'bank_account_holder' => ['sometimes', 'nullable', 'string', 'max:255'],
            'npwp' => ['sometimes', 'nullable', 'string', 'max:32'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $nama = $data['name'] ?? $existing?->name;
        $entityId = $existing?->entity_id ?? $request->user()->entity_id;

        $kembar = Payee::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $nama)])
            ->when($entityId === null, fn ($q) => $q->whereNull('entity_id'))
            ->when($entityId !== null, fn ($q) => $q->where('entity_id', $entityId))
            ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing->id))
            ->exists();

        if ($kembar) {
            throw ValidationException::withMessages([
                'name' => 'Penerima dengan nama ini sudah ada.',
            ]);
        }

        return $data;
    }

    /** Penerima dipakai kalau ada pembayaran yang menunjuknya, lewat id atau nama. */
    private function dipakai(Payee $p): bool
    {
        return Pembayaran::query()
            ->where('payee_id', $p->id)
            ->orWhereRaw('LOWER(payee_name) = ?', [mb_strtolower((string) $p->name)])
            ->exists();
    }

    /**
     * Penerima milik yayasan. User berjangkauan yayasan/vihara hanya melihat
     * penerima yayasannya sendiri plus yang berlaku lintas yayasan; superadmin
     * (entity_id NULL dan jangkauan global) melihat semuanya.
     */
    private function batasiYayasan($q, Request $request): void
    {
        $user = $request->user();
        if ($user->isGlobalScope()) {
            return;
        }

        $q->where(function ($w) use ($user) {
            $w->whereNull('entity_id')->orWhere('entity_id', $user->entity_id);
        });
    }

    private function guardYayasan(Request $request, Payee $p): void
    {
        $user = $request->user();
        if ($user->isGlobalScope()) {
            return;
        }
        abort_unless(
            $p->entity_id === null || $p->entity_id === $user->entity_id,
            403,
            'Penerima ini milik yayasan lain.',
        );
    }
}
