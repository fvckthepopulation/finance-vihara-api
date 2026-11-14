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
 *
 * Sejak FV-45 kelas ini juga menjadi dasar Master Penerimaan: bentuk masternya
 * identik (kategori → nama → akun COA), yang berbeda hanya JENIS transaksinya,
 * jenis akun yang sah, dan tabel yang diperiksa saat menentukan "sudah
 * dipakai". Ketiganya konstanta di bawah, dibaca lewat `static::` supaya
 * turunan hanya perlu menimpanya — bukan menyalin 350 baris aturan yang sama
 * dan membiarkan keduanya menyimpang.
 */
class MasterPengeluaranController extends Controller
{
    /** 'pengeluaran' | 'penerimaan' — nilai kolom type di kedua tabel master. */
    protected const TIPE = 'pengeluaran';

    /** Model transaksi yang menentukan apakah kategori/nama sudah terpakai. */
    protected const MODEL = Pembayaran::class;

    /** Jenis akun COA yang sah untuk nama di master ini. */
    protected const JENIS_AKUN = 'expense';

    /** Sebutan di pesan kesalahan: "Nama pengeluaran sudah ada…". */
    protected const SEBUTAN = 'pengeluaran';

    /** Sebutan akun di pesan validasi. */
    protected const SEBUTAN_AKUN = 'akun jenis beban';

    /**
     * Apakah akun COA wajib diisi saat menambah nama?
     *
     * Wajib untuk pengeluaran: akun debit jurnal memang diambil dari sini.
     * Untuk penerimaan akun per nama TIDAK ADA sama sekali — lihat
     * MasterPenerimaanController dan konstanta di bawah.
     */
    protected const AKUN_WAJIB = true;

    /**
     * Apakah akun di tingkat NAMA dipakai sama sekali?
     *
     * Penerimaan: tidak. Akunnya diturunkan dari pembatasan kategori x kanal,
     * dan akun per nama akan memutus aturan itu (FV-49).
     */
    protected const AKUN_PER_NAMA = true;

    /** Apakah kategori punya jenis pembatasan ISAK 35? (penerimaan saja) */
    protected const PUNYA_PEMBATASAN = false;

    // ── Kategori Biaya ─────────────────────────────────────────────────────

    public function kategoriIndex(Request $request): JsonResponse
    {
        $q = TransactionCategory::query()
            ->where('type', static::TIPE)
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
            // Pembatasan ISAK 35 + akun pendapatan yang akan dipakai per
            // kanal. Layar master menampilkannya, bukan lagi "—" (FV-49).
            'restriction' => $c->restriction,
            'restriction_label' => TransactionCategory::PEMBATASAN[$c->restriction] ?? null,
            'akun_per_metode' => $c->akunPerMetode(),
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
                    ->where('type', static::TIPE),
            ],
            'name' => ['required', 'string', 'max:255'],
            // Wajib untuk kategori dana: tanpa ini akun pendapatannya tidak
            // bisa diturunkan dan laporan ISAK 35 tidak tahu kolomnya (FV-49).
            'restriction' => [
                static::PUNYA_PEMBATASAN ? 'required' : 'sometimes',
                'nullable',
                Rule::in(array_keys(TransactionCategory::PEMBATASAN)),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'default_debit_code' => ['sometimes', 'nullable', 'string', 'exists:accounts,code'],
            'active' => ['sometimes', 'boolean'],
        ], [
            'code.regex' => 'Kode hanya boleh huruf kecil, angka, dan garis bawah.',
            'code.unique' => 'Kode kategori sudah dipakai.',
            'restriction.required' => 'Jenis pembatasan wajib dipilih.',
        ]);

        $kategori = TransactionCategory::create($data + [
            'type' => static::TIPE,
        ]);

        return response()->json($kategori, 201);
    }

    public function kategoriUpdate(Request $request, TransactionCategory $kategori): JsonResponse
    {
        $this->pastikanJenisBenar($kategori);

        $data = $request->validate([
            // `code` tidak boleh diubah: kolom pembayaran.category menyimpan
            // kode ini apa adanya, jadi mengubahnya memutus transaksi lama.
            'name' => ['sometimes', 'string', 'max:255'],
            'restriction' => [
                'sometimes', 'nullable',
                Rule::in(array_keys(TransactionCategory::PEMBATASAN)),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'default_debit_code' => ['sometimes', 'nullable', 'string', 'exists:accounts,code'],
            'active' => ['sometimes', 'boolean'],
        ]);

        // Pembatasan tidak boleh diubah begitu kategorinya dipakai transaksi:
        // jurnal lama sudah terlanjur memakai akun pendapatan hasil
        // pembatasan yang lama, dan mengubahnya membuat laporan ISAK 35 tidak
        // cocok lagi dengan buku besarnya. Nonaktifkan dan buat kategori baru
        // (FV-49 poin 5).
        if (array_key_exists('restriction', $data)
            && $data['restriction'] !== $kategori->restriction
            && $this->kategoriDipakai($kategori->code)) {
            return response()->json([
                'message' => 'Jenis pembatasan tidak bisa diubah: kategori ini sudah dipakai transaksi. '
                    . 'Nonaktifkan kategori ini lalu buat kategori baru.',
            ], 422);
        }

        // Kategori tidak boleh dinonaktifkan selama masih punya nama aktif —
        // kalau tidak, dropdown Nama Pengeluaran berisi pilihan yang kategori
        // induknya sudah hilang.
        if (array_key_exists('active', $data) && $data['active'] === false) {
            $aktif = $this->jumlahNama($kategori->code, true);
            if ($aktif > 0 && ! $request->boolean('nonaktifkan_anak')) {
                return response()->json([
                    'message' => "Kategori masih punya {$aktif} Nama " . ucfirst(static::SEBUTAN)
                        . ' aktif. Nonaktifkan nama-nama itu dulu, atau kirim '
                        . 'nonaktifkan_anak=true untuk menonaktifkan semuanya sekaligus.',
                    'jumlah_nama_aktif' => $aktif,
                ], 422);
            }
            if ($aktif > 0) {
                TransactionName::query()
                    ->where('type', static::TIPE)
                    ->where('category', $kategori->code)
                    ->update(['active' => false]);
            }
        }

        $kategori->update($data);

        return response()->json($kategori->fresh());
    }

    public function kategoriDestroy(TransactionCategory $kategori): JsonResponse
    {
        $this->pastikanJenisBenar($kategori);

        if ($this->kategoriDipakai($kategori->code)) {
            return response()->json([
                'message' => 'Kategori sudah dipakai transaksi — tidak bisa dihapus. Nonaktifkan saja.',
            ], 422);
        }
        if ($this->jumlahNama($kategori->code, null) > 0) {
            return response()->json([
                'message' => 'Kategori masih punya Nama ' . ucfirst(static::SEBUTAN)
                    . '. Hapus atau pindahkan dulu nama-namanya.',
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
            ->where('type', static::TIPE)
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

        $label = TransactionCategory::labels(static::TIPE);
        // Kategori dimuat sekali untuk mewariskan akunnya ke tiap nama.
        $kategoriMaster = TransactionCategory::query()
            ->where('type', static::TIPE)
            ->get()
            ->keyBy('code');

        $halaman->getCollection()->transform(fn (TransactionName $n) => [
            'id' => $n->id,
            'code' => $n->code,
            'category' => $n->category,
            'category_label' => $label[$n->category] ?? $n->category,
            'name' => $n->name,
            'coa_account_code' => $n->coa_account_code,
            'coa_account_name' => $n->coaAccount?->name,
            // Untuk penerimaan, akun DIWARISI dari kategorinya dan ditampilkan
            // per kanal — bukan "—" seperti keluhan FV-49.
            'akun_per_metode' => static::AKUN_PER_NAMA
                ? null
                : $kategoriMaster->get($n->category)?->akunPerMetode(),
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

        $nama = TransactionName::create($data + ['type' => static::TIPE]);

        return response()->json($nama, 201);
    }

    public function namaUpdate(Request $request, TransactionName $nama): JsonResponse
    {
        abort_unless($nama->type === static::TIPE, 404);

        $nama->update($this->validasiNama($request, $nama));

        return response()->json($nama->fresh());
    }

    public function namaDestroy(TransactionName $nama): JsonResponse
    {
        abort_unless($nama->type === static::TIPE, 404);

        if ($this->namaDipakai($nama->name)) {
            return response()->json([
                'message' => 'Nama ' . static::SEBUTAN
                    . ' sudah dipakai transaksi — tidak bisa dihapus. Nonaktifkan saja.',
            ], 422);
        }

        $nama->delete();

        return response()->json(['message' => 'deleted']);
    }

    // ── Bantuan ────────────────────────────────────────────────────────────

    protected function validasiNama(Request $request, ?TransactionName $existing): array
    {
        $baru = $existing === null;

        $data = $request->validate([
            'category' => [
                $baru ? 'required' : 'sometimes',
                Rule::exists('transaction_categories', 'code')
                    ->where('type', static::TIPE),
            ],
            'name' => [$baru ? 'required' : 'sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:48'],
            'coa_account_code' => [
                $baru && static::AKUN_WAJIB && static::AKUN_PER_NAMA ? 'required' : 'sometimes',
                'nullable',
                'string',
                'exists:accounts,code',
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'vihara_id' => ['sometimes', 'nullable', 'integer', 'exists:viharas,id'],
            'active' => ['sometimes', 'boolean'],
        ], [
            'coa_account_code.exists' => 'Akun harus ' . static::SEBUTAN_AKUN . '.',
        ]);

        $kategori = $data['category'] ?? $existing?->category;
        $nama = $data['name'] ?? $existing?->name;
        $viharaId = array_key_exists('vihara_id', $data)
            ? $data['vihara_id']
            : $existing?->vihara_id;

        $kembar = TransactionName::query()
            ->where('type', static::TIPE)
            ->where('category', $kategori)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $nama)])
            ->when($viharaId === null, fn ($q) => $q->whereNull('vihara_id'))
            ->when($viharaId !== null, fn ($q) => $q->where('vihara_id', $viharaId))
            ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing->id))
            ->exists();

        if ($kembar) {
            throw ValidationException::withMessages([
                'name' => 'Nama ' . static::SEBUTAN . ' sudah ada di kategori ini.',
            ]);
        }

        // Jenis yang tidak memakai akun per nama (penerimaan) membuang isian
        // itu apa adanya: akunnya ditentukan kategori, dan menyimpan nilai di
        // sini hanya akan menyesatkan pembaca master (FV-49).
        if (! static::AKUN_PER_NAMA) {
            $data['coa_account_code'] = null;

            return $data;
        }

        // Akunnya harus akun beban. Satu pengecualian yang memang ada di bagan
        // akun ini: kategori seperti "Pelunasan Utang Vendor Kegiatan" men-debit
        // akun kewajiban (2110), bukan beban. Jadi akun bawaan kategorinya
        // sendiri selalu boleh dipakai.
        $akun = $data['coa_account_code'] ?? $existing?->coa_account_code;
        if ($akun !== null) {
            $bawaanKategori = TransactionCategory::query()
                ->where('type', static::TIPE)
                ->where('code', $kategori)
                ->value('default_debit_code');
            $jenis = Account::query()->where('code', $akun)->value('type');
            if ($jenis !== static::JENIS_AKUN && $akun !== $bawaanKategori) {
                throw ValidationException::withMessages([
                    'coa_account_code' => 'Akun harus ' . static::SEBUTAN_AKUN
                        . ' (atau akun bawaan kategori ini).',
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
            ->where('type', static::TIPE)
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
                ->where(fn ($q) => $q->where('type', static::JENIS_AKUN)->orWhereIn('code', $bawaan))
                ->orderBy('code')
                ->get(['code', 'name', 'type']),
        ]);
    }

    protected function jumlahNama(string $kategori, ?bool $aktif): int
    {
        return TransactionName::query()
            ->where('type', static::TIPE)
            ->where('category', $kategori)
            ->when($aktif !== null, fn ($q) => $q->where('active', $aktif))
            ->count();
    }

    protected function kategoriDipakai(string $kategori): bool
    {
        return (static::MODEL)::query()->where('category', $kategori)->exists();
    }

    protected function namaDipakai(?string $nama): bool
    {
        return $nama !== null && (static::MODEL)::query()->where('name', $nama)->exists();
    }

    /** Kategori dari jenis master LAIN tidak ada di sini — 404, bukan 403. */
    protected function pastikanJenisBenar(TransactionCategory $kategori): void
    {
        abort_unless($kategori->type === static::TIPE, 404);
    }
}
