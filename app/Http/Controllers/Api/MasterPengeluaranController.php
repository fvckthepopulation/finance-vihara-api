<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Pembayaran;
use App\Models\TransactionCategory;
use App\Models\TransactionName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Master Pengeluaran (FV-29) — Kategori Biaya sebagai induk, Nama Pengeluaran
 * sebagai anaknya.
 *
 * Dua aturan yang menjaga pembukuan tetap utuh:
 *   1. Yang sudah dipakai transaksi tidak boleh dihapus, hanya dinonaktifkan.
 *      Kolom pembayaran.category / pembayaran.name menyimpan kode dan nama apa
 *      adanya, jadi baris lama tetap terbaca walau masternya berubah.
 *   2. Akun beban di tingkat Nama Pengeluaran adalah yang dipakai jurnal.
 *      Kalau kosong, dipakai akun bawaan kategorinya.
 */
class MasterPengeluaranController extends Controller
{
    private const TIPE = 'pengeluaran';

    // ── Kategori Biaya ─────────────────────────────────────────────────────

    public function kategoriIndex(Request $request): JsonResponse
    {
        $q = TransactionCategory::query()
            ->where('type', TransactionCategory::TYPE_PENGELUARAN)
            ->orderBy('name');

        if ($search = $request->string('q')->toString()) {
            $like = '%'.$search.'%';
            $q->where(function ($w) use ($like) {
                $w->where('name', 'ilike', $like)
                    ->orWhere('code', 'ilike', $like)
                    ->orWhere('description', 'ilike', $like);
            });
        }
        if ($request->has('active') && $request->string('active')->toString() !== '') {
            $q->where('active', $request->boolean('active'));
        }

        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);
        $halaman = $q->paginate($perPage);

        $halaman->getCollection()->transform(fn (TransactionCategory $c) => [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'description' => $c->description,
            'default_debit_code' => $c->default_debit_code,
            'active' => (bool) $c->active,
            'jumlah_nama' => $this->jumlahNama($c->code, null),
            'jumlah_nama_aktif' => $this->jumlahNama($c->code, true),
            'in_use' => $this->kategoriDipakai($c->code),
        ]);

        return response()->json($halaman);
    }

    public function kategoriStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:48', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('transaction_categories', 'code')
                    ->where('type', TransactionCategory::TYPE_PENGELUARAN),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'default_debit_code' => ['sometimes', 'nullable', 'string', 'exists:accounts,code'],
            'active' => ['sometimes', 'boolean'],
        ], [
            'code.regex' => 'Kode hanya boleh huruf kecil, angka, dan garis bawah.',
            'code.unique' => 'Kode kategori sudah dipakai.',
        ]);

        $kategori = TransactionCategory::create($data + [
            'type' => TransactionCategory::TYPE_PENGELUARAN,
        ]);

        return response()->json($kategori, 201);
    }

    public function kategoriUpdate(Request $request, TransactionCategory $kategori): JsonResponse
    {
        $this->pastikanPengeluaran($kategori);

        $data = $request->validate([
            // `code` tidak boleh diubah: kolom pembayaran.category menyimpan
            // kode ini apa adanya, jadi mengubahnya memutus transaksi lama.
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'default_debit_code' => ['sometimes', 'nullable', 'string', 'exists:accounts,code'],
            'active' => ['sometimes', 'boolean'],
        ]);

        // Kategori tidak boleh dinonaktifkan selama masih punya nama aktif —
        // kalau tidak, dropdown Nama Pengeluaran berisi pilihan yang kategori
        // induknya sudah hilang.
        if (array_key_exists('active', $data) && $data['active'] === false) {
            $aktif = $this->jumlahNama($kategori->code, true);
            if ($aktif > 0 && ! $request->boolean('nonaktifkan_anak')) {
                return response()->json([
                    'message' => "Kategori masih punya {$aktif} Nama Pengeluaran aktif. "
                        . 'Nonaktifkan nama-nama itu dulu, atau kirim nonaktifkan_anak=true '
                        . 'untuk menonaktifkan semuanya sekaligus.',
                    'jumlah_nama_aktif' => $aktif,
                ], 422);
            }
            if ($aktif > 0) {
                TransactionName::query()
                    ->where('type', self::TIPE)
                    ->where('category', $kategori->code)
                    ->update(['active' => false]);
            }
        }

        $kategori->update($data);

        return response()->json($kategori->fresh());
    }

    public function kategoriDestroy(TransactionCategory $kategori): JsonResponse
    {
        $this->pastikanPengeluaran($kategori);

        if ($this->kategoriDipakai($kategori->code)) {
            return response()->json([
                'message' => 'Kategori sudah dipakai transaksi — tidak bisa dihapus. Nonaktifkan saja.',
            ], 422);
        }
        if ($this->jumlahNama($kategori->code, null) > 0) {
            return response()->json([
                'message' => 'Kategori masih punya Nama Pengeluaran. Hapus atau pindahkan dulu nama-namanya.',
            ], 422);
        }

        $kategori->delete();

        return response()->json(['message' => 'deleted']);
    }

    // ── Nama Pengeluaran ───────────────────────────────────────────────────

    public function namaIndex(Request $request): JsonResponse
    {
        $q = TransactionName::query()
            ->with(['coaAccount:code,name', 'vihara:id,name'])
            ->where('type', self::TIPE)
            ->orderBy('category')
            ->orderByRaw('LOWER(name)');

        if ($kategori = $request->string('category')->toString()) {
            $q->where('category', $kategori);
        }
        if ($search = $request->string('q')->toString()) {
            $like = '%'.$search.'%';
            $q->where(function ($w) use ($like) {
                $w->where('name', 'ilike', $like)
                    ->orWhere('code', 'ilike', $like)
                    ->orWhere('description', 'ilike', $like);
            });
        }
        if ($request->has('active') && $request->string('active')->toString() !== '') {
            $q->where('active', $request->boolean('active'));
        }

        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);
        $halaman = $q->paginate($perPage);

        $label = TransactionCategory::labelsPengeluaran();
        $halaman->getCollection()->transform(fn (TransactionName $n) => [
            'id' => $n->id,
            'code' => $n->code,
            'category' => $n->category,
            'category_label' => $label[$n->category] ?? $n->category,
            'name' => $n->name,
            'coa_account_code' => $n->coa_account_code,
            'coa_account_name' => $n->coaAccount?->name,
            'description' => $n->description,
            'vihara_id' => $n->vihara_id,
            'vihara_name' => $n->vihara?->name,
            'active' => (bool) $n->active,
            'in_use' => $this->namaDipakai($n->name),
        ]);

        return response()->json($halaman);
    }

    public function namaStore(Request $request): JsonResponse
    {
        $data = $this->validasiNama($request, null);

        $nama = TransactionName::create($data + ['type' => self::TIPE]);

        return response()->json($nama, 201);
    }

    public function namaUpdate(Request $request, TransactionName $nama): JsonResponse
    {
        abort_unless($nama->type === self::TIPE, 404);

        $nama->update($this->validasiNama($request, $nama));

        return response()->json($nama->fresh());
    }

    public function namaDestroy(TransactionName $nama): JsonResponse
    {
        abort_unless($nama->type === self::TIPE, 404);

        if ($this->namaDipakai($nama->name)) {
            return response()->json([
                'message' => 'Nama pengeluaran sudah dipakai transaksi — tidak bisa dihapus. Nonaktifkan saja.',
            ], 422);
        }

        $nama->delete();

        return response()->json(['message' => 'deleted']);
    }

    // ── Bantuan ────────────────────────────────────────────────────────────

    private function validasiNama(Request $request, ?TransactionName $existing): array
    {
        $baru = $existing === null;

        $data = $request->validate([
            'category' => [
                $baru ? 'required' : 'sometimes',
                Rule::exists('transaction_categories', 'code')
                    ->where('type', TransactionCategory::TYPE_PENGELUARAN),
            ],
            'name' => [$baru ? 'required' : 'sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:48'],
            'coa_account_code' => [
                $baru ? 'required' : 'sometimes',
                'string',
                'exists:accounts,code',
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'vihara_id' => ['sometimes', 'nullable', 'integer', 'exists:viharas,id'],
            'active' => ['sometimes', 'boolean'],
        ], [
            'coa_account_code.exists' => 'Akun biaya harus akun jenis beban.',
        ]);

        $kategori = $data['category'] ?? $existing?->category;
        $nama = $data['name'] ?? $existing?->name;
        $viharaId = array_key_exists('vihara_id', $data)
            ? $data['vihara_id']
            : $existing?->vihara_id;

        $kembar = TransactionName::query()
            ->where('type', self::TIPE)
            ->where('category', $kategori)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $nama)])
            ->when($viharaId === null, fn ($q) => $q->whereNull('vihara_id'))
            ->when($viharaId !== null, fn ($q) => $q->where('vihara_id', $viharaId))
            ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing->id))
            ->exists();

        if ($kembar) {
            throw ValidationException::withMessages([
                'name' => 'Nama pengeluaran sudah ada di kategori ini.',
            ]);
        }

        // Akunnya harus akun beban. Satu pengecualian yang memang ada di bagan
        // akun ini: kategori seperti "Pelunasan Utang Vendor Kegiatan" men-debit
        // akun kewajiban (2110), bukan beban. Jadi akun bawaan kategorinya
        // sendiri selalu boleh dipakai.
        $akun = $data['coa_account_code'] ?? $existing?->coa_account_code;
        if ($akun !== null) {
            $bawaanKategori = TransactionCategory::query()
                ->where('type', TransactionCategory::TYPE_PENGELUARAN)
                ->where('code', $kategori)
                ->value('default_debit_code');
            $jenis = Account::query()->where('code', $akun)->value('type');
            if ($jenis !== 'expense' && $akun !== $bawaanKategori) {
                throw ValidationException::withMessages([
                    'coa_account_code' => 'Akun biaya harus akun jenis beban '
                        . '(atau akun bawaan kategori ini).',
                ]);
            }
        }

        return $data;
    }

    /**
     * Daftar akun untuk dropdown akun di layar master: seluruh akun beban,
     * ditambah akun bawaan kategori yang kebetulan bukan beban (lihat catatan
     * di validasiNama) supaya kategori seperti utang_lain tetap bisa dipakai.
     */
    public function akunBeban(Request $request): JsonResponse
    {
        $bawaan = TransactionCategory::query()
            ->where('type', TransactionCategory::TYPE_PENGELUARAN)
            ->when(
                $request->filled('category'),
                fn ($q) => $q->where('code', $request->string('category')->toString()),
            )
            ->pluck('default_debit_code')
            ->filter()
            ->unique()
            ->all();

        return response()->json([
            'data' => Account::query()
                ->where('active', true)
                ->where('is_header', false)
                ->where(fn ($q) => $q->where('type', 'expense')->orWhereIn('code', $bawaan))
                ->orderBy('code')
                ->get(['code', 'name', 'type']),
        ]);
    }

    private function jumlahNama(string $kategori, ?bool $aktif): int
    {
        return TransactionName::query()
            ->where('type', self::TIPE)
            ->where('category', $kategori)
            ->when($aktif !== null, fn ($q) => $q->where('active', $aktif))
            ->count();
    }

    private function kategoriDipakai(string $kategori): bool
    {
        return Pembayaran::query()->where('category', $kategori)->exists();
    }

    private function namaDipakai(?string $nama): bool
    {
        return $nama !== null && Pembayaran::query()->where('name', $nama)->exists();
    }

    private function pastikanPengeluaran(TransactionCategory $kategori): void
    {
        abort_unless($kategori->type === TransactionCategory::TYPE_PENGELUARAN, 404);
    }
}
