<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pembayaran;
use App\Models\Penerimaan;
use App\Models\PettyCashMovement;
use App\Models\Entity;
use App\Models\Vihara;
use App\Models\ViharaBankAccount;
use App\Services\ViharaScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Master rekening per vihara — rekening bank dan kas tunai (FV-30). */
class ViharaBankAccountController extends Controller
{
    /**
     * Lookup untuk dropdown: hanya rekening AKTIF, tanpa pagination, karena
     * pemanggilnya mengisi <select> dan butuh seluruh pilihan.
     *
     * `type=bank` dipakai form Input Pengeluaran — kas tunai tidak boleh
     * muncul di "Transfer dari Rekening".
     */
    public function index(Request $request): JsonResponse
    {
        $q = ViharaBankAccount::query()
            ->with('entity:id,code,legal_name')
            ->where('active', true)
            ->orderBy('bank_name')
            ->orderBy('id');

        if ($type = $request->string('type')->toString()) {
            $q->where('type', $type);
        }

        // Rekening milik yayasan (FV-36): form menyebut VIHARA transaksinya,
        // dan yang ditawarkan adalah rekening yayasan vihara itu yang memang
        // boleh dipakai olehnya.
        if ($request->filled('vihara_id')) {
            $viharaId = $request->integer('vihara_id');
            ViharaScopeService::guardRecord($request, $viharaId);
            $q->usableByVihara($viharaId);
        } else {
            $this->batasiYayasan($q, $request);
        }

        return response()->json([
            'data' => $q->get([
                'id', 'entity_id', 'type', 'bank_name', 'account_number',
                'account_name', 'coa_account_code', 'description', 'active',
            ])->map(fn (ViharaBankAccount $b) => $b->only([
                'id', 'entity_id', 'type', 'bank_name', 'account_number',
                'account_name', 'coa_account_code', 'description', 'active',
            ]) + ['entity_name' => $b->entity?->legal_name]),
        ]);
    }

    /**
     * Daftar untuk layar Master Rekening: lintas vihara dalam jangkauan user,
     * termasuk yang nonaktif, dengan pencarian + pagination (FV-26/FV-27).
     */
    public function manage(Request $request): JsonResponse
    {
        $q = ViharaBankAccount::query()
            ->with(['entity:id,code,legal_name', 'viharas:id,name,code', 'coaAccount:code,name'])
            ->orderBy('entity_id')
            ->orderBy('bank_name')
            ->orderBy('id');

        if ($type = $request->string('type')->toString()) {
            $q->where('type', $type);
        }
        if ($request->has('active') && $request->string('active')->toString() !== '') {
            $q->where('active', $request->boolean('active'));
        }
        if ($search = $request->string('q')->toString()) {
            $like = '%'.$search.'%';
            $q->where(function ($w) use ($like) {
                $w->where('bank_name', 'ilike', $like)
                    ->orWhere('account_number', 'ilike', $like)
                    ->orWhere('account_name', 'ilike', $like)
                    ->orWhere('description', 'ilike', $like);
            });
        }

        if ($request->filled('vihara_id')) {
            $viharaId = $request->integer('vihara_id');
            ViharaScopeService::guardRecord($request, $viharaId);
            $q->usableByVihara($viharaId);
        } else {
            $this->batasiYayasan($q, $request);
        }

        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);
        $halaman = $q->paginate($perPage);

        $halaman->getCollection()->transform(fn (ViharaBankAccount $b) => [
            'id' => $b->id,
            'entity_id' => $b->entity_id,
            'entity_name' => $b->entity?->legal_name,
            // Kosong = seluruh vihara di yayasan itu (FV-36).
            'vihara_ids' => $b->viharas->pluck('id')->all(),
            'vihara_names' => $b->viharas->pluck('name')->all(),
            'type' => $b->type,
            'type_label' => ViharaBankAccount::TYPES[$b->type] ?? $b->type,
            'bank_name' => $b->bank_name,
            'account_number' => $b->account_number,
            'account_name' => $b->account_name,
            'coa_account_code' => $b->coa_account_code,
            'coa_account_name' => $b->coaAccount?->name,
            'description' => $b->description,
            'active' => (bool) $b->active,
            'label' => $b->label(),
            // Dipakai layar master untuk menyembunyikan tombol Hapus: yang
            // sudah dipakai transaksi hanya boleh dinonaktifkan.
            'in_use' => $this->dipakaiTransaksi($b),
        ]);

        return response()->json($halaman);
    }

    public function store(Request $request, Entity $entity): JsonResponse
    {
        ViharaScopeService::guardEntity($request, $entity->id);

        $data = $this->validasi($request, null);
        $pengguna = $this->viharaPengguna($request, $entity);

        $account = $entity->bankAccounts()->create($data + [
            'type' => $data['type'] ?? ViharaBankAccount::TYPE_BANK,
            'coa_account_code' => $data['coa_account_code'] ?? '1130',
        ]);
        $account->viharas()->sync($pengguna);

        return response()->json($account->fresh()->load('viharas:id,name'), 201);
    }

    public function update(Request $request, ViharaBankAccount $viharaBankAccount): JsonResponse
    {
        ViharaScopeService::guardEntity($request, $viharaBankAccount->entity_id);

        $viharaBankAccount->update($this->validasi($request, $viharaBankAccount));

        // vihara_ids dikirim = daftar pengguna diganti; [] berarti semua vihara.
        if ($request->has('vihara_ids')) {
            $viharaBankAccount->viharas()->sync(
                $this->viharaPengguna($request, $viharaBankAccount->entity),
            );
        }

        return response()->json($viharaBankAccount->fresh()->load('viharas:id,name'));
    }

    public function destroy(Request $request, ViharaBankAccount $viharaBankAccount): JsonResponse
    {
        ViharaScopeService::guardEntity($request, $viharaBankAccount->entity_id);

        if ($this->dipakaiTransaksi($viharaBankAccount)) {
            return response()->json([
                'message' => 'Rekening sudah dipakai transaksi — tidak bisa dihapus. Nonaktifkan saja.',
            ], 422);
        }

        $viharaBankAccount->delete();

        return response()->json(['message' => 'deleted']);
    }

    /**
     * Aturan isian. Kas tunai tidak punya nomor rekening, jadi nama bank dan
     * nomor rekening hanya wajib untuk jenis Bank. Nomor rekening tidak boleh
     * kembar untuk bank yang sama — pesannya disebut apa adanya supaya
     * operator tahu apa yang bentrok.
     */
    private function validasi(Request $request, ?ViharaBankAccount $existing): array
    {
        $baru = $existing === null;
        $type = $request->string('type')->toString()
            ?: ($existing->type ?? ViharaBankAccount::TYPE_BANK);
        $bank = $type === ViharaBankAccount::TYPE_BANK;

        $data = $request->validate([
            'type' => [$baru ? 'sometimes' : 'sometimes', Rule::in(array_keys(ViharaBankAccount::TYPES))],
            'bank_name' => [$baru ? 'required' : 'sometimes', 'string', 'max:64'],
            'account_number' => [
                $baru && $bank ? 'required' : 'sometimes',
                'nullable',
                'string',
                'max:32',
            ],
            'account_name' => [$baru ? 'required' : 'sometimes', 'nullable', 'string', 'max:255'],
            'coa_account_code' => [$baru ? 'sometimes' : 'sometimes', 'nullable', 'string', 'exists:accounts,code'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $namaBank = $data['bank_name'] ?? $existing?->bank_name;
        $nomor = $data['account_number'] ?? $existing?->account_number;

        if ($bank && $nomor) {
            $kembar = ViharaBankAccount::query()
                ->where('bank_name', $namaBank)
                ->where('account_number', $nomor)
                ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing->id))
                ->exists();

            if ($kembar) {
                throw ValidationException::withMessages([
                    'account_number' => 'Nomor rekening sudah terdaftar.',
                ]);
            }
        }

        return $data;
    }

    /**
     * Vihara pengguna yang dikirim klien, dipastikan milik yayasan rekening
     * itu. Kosong / tidak dikirim = seluruh vihara yayasan (FV-36 poin 2).
     */
    private function viharaPengguna(Request $request, Entity $entity): array
    {
        $request->validate([
            'vihara_ids' => ['sometimes', 'nullable', 'array'],
            'vihara_ids.*' => ['integer', 'exists:viharas,id'],
        ]);

        $ids = array_map('intval', $request->input('vihara_ids') ?? []);
        if ($ids === []) {
            return [];
        }

        $milikYayasan = $entity->viharas()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $asing = array_diff($ids, $milikYayasan);
        if ($asing !== []) {
            throw ValidationException::withMessages([
                'vihara_ids' => 'Ada vihara yang bukan milik yayasan pemilik rekening ini.',
            ]);
        }

        return $ids;
    }

    /** Daftar rekening dibatasi yayasan user; global melihat semuanya. */
    private function batasiYayasan($q, Request $request): void
    {
        $user = $request->user();
        if ($user->isGlobalScope()) {
            return;
        }
        $q->where('entity_id', $user->entity_id);
    }

    private function dipakaiTransaksi(ViharaBankAccount $b): bool
    {
        return Penerimaan::where('bank_account_id', $b->id)->exists()
            || Pembayaran::where('bank_account_id', $b->id)->exists()
            || PettyCashMovement::where('source_bank_account_id', $b->id)->exists();
    }
}
