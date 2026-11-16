<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KonsolidasiGroup;
use App\Models\KonsolidasiGroupMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Master Grup Konsolidasi (FV-66): nama grup, yayasan anggota beserta
 * periode keanggotaannya, dan user yang boleh melihat laporan grup.
 *
 * Aturan: satu yayasan tidak boleh menjadi anggota lebih dari satu grup pada
 * periode yang sama — eliminasi dan hak akses jadi ambigu kalau boleh.
 */
class KonsolidasiGroupController extends Controller
{
    /** Daftar grup — untuk master (semua) dan untuk pilihan laporan (sesuai akses). */
    public function index(Request $request): JsonResponse
    {
        $grup = $request->boolean('master')
            ? KonsolidasiGroup::query()->orderBy('name')
            : KonsolidasiGroup::untukUser($request->user())->where('active', true);

        return response()->json([
            'data' => $grup->with(['members.entity:id,code,legal_name', 'users:id,name,email'])->get()
                ->map(fn (KonsolidasiGroup $g) => $this->tampil($g)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validasi($request);
        $grup = DB::transaction(function () use ($data) {
            $grup = KonsolidasiGroup::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'active' => $data['active'] ?? true,
            ]);
            $this->simpanIsi($grup, $data);

            return $grup;
        });

        return response()->json($this->tampil($grup->fresh(['members.entity', 'users'])), 201);
    }

    public function update(Request $request, KonsolidasiGroup $konsolidasiGroup): JsonResponse
    {
        $data = $this->validasi($request, $konsolidasiGroup);
        DB::transaction(function () use ($data, $konsolidasiGroup) {
            $konsolidasiGroup->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'active' => $data['active'] ?? $konsolidasiGroup->active,
            ]);
            $this->simpanIsi($konsolidasiGroup, $data);
        });

        return response()->json($this->tampil($konsolidasiGroup->fresh(['members.entity', 'users'])));
    }

    public function destroy(KonsolidasiGroup $konsolidasiGroup): JsonResponse
    {
        // Grup hanya lapisan laporan: menghapusnya tidak menyentuh transaksi.
        $konsolidasiGroup->delete();

        return response()->json(['message' => 'deleted']);
    }

    private function validasi(Request $request, ?KonsolidasiGroup $grup = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('konsolidasi_groups', 'name')->ignore($grup?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'active' => ['sometimes', 'boolean'],
            'members' => ['required', 'array', 'min:1'],
            'members.*.entity_id' => ['required', 'integer', 'exists:entities,id'],
            'members.*.start_date' => ['required', 'date'],
            'members.*.end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:members.*.start_date'],
            'user_ids' => ['sometimes', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ], [
            'members.required' => 'Grup harus punya minimal satu yayasan anggota.',
            'members.*.end_date.after_or_equal' => 'Tanggal selesai keanggotaan tidak boleh sebelum tanggal mulai.',
        ]);

        $nama = DB::table('entities')->pluck('legal_name', 'id');
        $anggota = $data['members'];

        // Bentrok di dalam grup ini sendiri (yayasan sama, periode bertumpuk).
        foreach ($anggota as $i => $a) {
            foreach ($anggota as $j => $b) {
                if ($j <= $i || (int) $a['entity_id'] !== (int) $b['entity_id']) {
                    continue;
                }
                if ($this->bertumpuk($a['start_date'], $a['end_date'] ?? null, $b['start_date'], $b['end_date'] ?? null)) {
                    abort(response()->json([
                        'message' => "Keanggotaan {$nama[$a['entity_id']]} di grup ini bertumpuk periodenya.",
                        'errors' => ["members.$j.start_date" => ['Periode keanggotaan bertumpuk.']],
                    ], 422));
                }
            }
        }

        // Bentrok dengan grup lain.
        foreach ($anggota as $i => $a) {
            $lain = KonsolidasiGroupMember::query()
                ->with('group:id,name')
                ->where('entity_id', $a['entity_id'])
                ->when($grup, fn ($q) => $q->where('konsolidasi_group_id', '!=', $grup->id))
                ->get();
            foreach ($lain as $m) {
                if ($this->bertumpuk($a['start_date'], $a['end_date'] ?? null, $m->start_date->toDateString(), $m->end_date?->toDateString())) {
                    $periode = $m->start_date->format('d/m/Y').' s/d '.($m->end_date?->format('d/m/Y') ?? 'sekarang');
                    abort(response()->json([
                        'message' => "{$nama[$a['entity_id']]} sudah menjadi anggota grup \"{$m->group->name}\" pada periode {$periode}. "
                            .'Satu yayasan hanya boleh di satu grup pada periode yang sama.',
                        'errors' => ["members.$i.entity_id" => ['Bentrok dengan grup lain.']],
                    ], 422));
                }
            }
        }

        return $data;
    }

    private function bertumpuk(string $mulaiA, ?string $selesaiA, string $mulaiB, ?string $selesaiB): bool
    {
        $akhirA = $selesaiA ?? '9999-12-31';
        $akhirB = $selesaiB ?? '9999-12-31';

        return $mulaiA <= $akhirB && $mulaiB <= $akhirA;
    }

    private function simpanIsi(KonsolidasiGroup $grup, array $data): void
    {
        $grup->members()->delete();
        foreach ($data['members'] as $a) {
            $grup->members()->create([
                'entity_id' => $a['entity_id'],
                'start_date' => $a['start_date'],
                'end_date' => $a['end_date'] ?? null,
            ]);
        }
        if (array_key_exists('user_ids', $data)) {
            $grup->users()->sync($data['user_ids']);
        }
    }

    private function tampil(KonsolidasiGroup $g): array
    {
        return [
            'id' => $g->id,
            'name' => $g->name,
            'description' => $g->description,
            'active' => (bool) $g->active,
            'members' => $g->members->map(fn ($m) => [
                'id' => $m->id,
                'entity_id' => $m->entity_id,
                'code' => $m->entity?->code,
                'nama' => $m->entity?->legal_name,
                'start_date' => $m->start_date?->toDateString(),
                'end_date' => $m->end_date?->toDateString(),
            ])->values(),
            'users' => $g->users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->values(),
        ];
    }
}
