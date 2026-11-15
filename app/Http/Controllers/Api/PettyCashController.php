<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VoidService;
use App\Models\PettyCashMovement;
use App\Models\PettyCashPic;
use App\Models\TransactionCategory;
use App\Models\TransactionName;
use App\Models\Vihara;
use App\Models\ViharaBankAccount;
use App\Services\PeriodLockService;
use Illuminate\Validation\Rule;
use App\Services\ViharaScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PettyCashController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PettyCashMovement::query()
            ->with([
                'entity:id,code',
                'vihara:id,code,name',
                'pic:id,name',
                'sourceBankAccount:id,bank_name,account_number',
                'cashAccount:code,name',
                'counterpartAccount:code,name',
            ])
            ->orderByDesc('movement_date')
            ->orderByDesc('id');

        ViharaScopeService::applyScope($query, $request);
        if ($picId = $request->integer('pic_id')) {
            $query->where('pic_id', $picId);
        }
        if ($subtype = $request->string('subtype')->toString()) {
            $query->where('subtype', $subtype);
        }
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($type = $request->string('type')->toString()) {
            $query->where('type', $type);
        }
        // Pencarian (FV-27): nama pengeluaran, keterangan, catatan, no. bukti,
        // dan nama PIC.
        if ($cari = $request->string('q')->toString()) {
            $like = '%' . mb_strtolower($cari) . '%';
            $query->where(function ($w) use ($like) {
                $w->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(notes) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(reference) LIKE ?', [$like])
                    ->orWhereHas('pic', fn ($p) => $p->whereRaw('LOWER(name) LIKE ?', [$like]));
            });
        }

        if ($entityId = $request->integer('entity_id')) {
            $query->where('entity_id', $entityId);
        }
        // Filter bulan riwayat (YYYY-MM), panel Petty Cash mockup.
        if (preg_match('/^\d{4}-\d{2}$/', $month = $request->string('month')->toString())) {
            $query->whereRaw("to_char(movement_date, 'YYYY-MM') = ?", [$month]);
        }

        // Rentang tanggal riwayat (FV-86), inklusif; boleh salah satu saja.
        $request->validate([
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
        ], [
            'to.after_or_equal' => 'Sampai tanggal tidak boleh lebih awal dari Dari tanggal.',
        ]);
        if ($from = $request->string('from')->toString()) {
            $query->where('movement_date', '>=', $from);
        }
        if ($to = $request->string('to')->toString()) {
            $query->where('movement_date', '<=', $to);
        }

        // Total periode yang sedang disaring — hanya yang terposting, sama
        // dengan aturan saldo (FV-86, pertanggungjawaban PIC).
        $total = (clone $query)->setEagerLoads([])->reorder()->where('status', 'posted')
            ->toBase()
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN subtype = 'topup' THEN amount ELSE 0 END), 0) AS masuk, "
                . "COALESCE(SUM(CASE WHEN subtype = 'usage' THEN amount ELSE 0 END), 0) AS keluar",
            )->first();

        // Jumlah baris mengikuti pilihan pengguna (FV-26), dibatasi 1..100.
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);
        $paginator = $query->paginate($perPage)->through(function (PettyCashMovement $m) {
            return $m->toArray() + [
                'pic_name' => $m->pic?->name,
                'source_bank_label' => $m->sourceBankAccount?->label(),
                'category_label' => $this->labelKategori($m->category),
            ];
        });

        $masuk = (float) ($total->masuk ?? 0);
        $keluar = (float) ($total->keluar ?? 0);

        return response()->json($paginator->toArray() + [
            'totals' => [
                'masuk' => number_format($masuk, 2, '.', ''),
                'keluar' => number_format($keluar, 2, '.', ''),
                'selisih' => number_format($masuk - $keluar, 2, '.', ''),
            ],
        ]);
    }

    /**
     * Ringkasan Kas Kecil per PIC: saldo awal (topup posted pertama),
     * total isi/pakai, dan saldo berjalan. Hanya movement posted.
     */
    /**
     * Ringkasan kas kecil per PIC (kartu di layar Kas Kecil).
     *
     * Angkanya PER BULAN BERJALAN, dan selalu berlaku:
     *   Saldo Awal + Pengisian − Terpakai = Saldo Saat Ini.
     *
     * Dulu saldo_awal diisi nominal topup posted PERTAMA, padahal topup itu
     * juga ikut dijumlahkan di total pengisian — jadi angkanya dihitung dua
     * kali dan kartunya tidak pernah bisa dicocokkan (FV-60). Saldo Awal
     * yang benar adalah saldo PIC itu SEBELUM bulan berjalan.
     *
     * Draft dan yang dibatalkan tidak ikut dihitung di keempat angka: hanya
     * baris berstatus posted yang dijumlahkan.
     */
    public function summary(Request $request): JsonResponse
    {
        $viharaIds = ViharaScopeService::readFilterIds($request);

        $pics = PettyCashPic::query()
            ->where('active', true)
            ->when($viharaIds !== null, fn ($q) => $q->whereIn('vihara_id', $viharaIds))
            ->orderBy('name')
            ->get(['id', 'vihara_id', 'name']);

        $awalBulan = now()->startOfMonth()->toDateString();

        $jumlahkan = fn ($query) => $query
            ->where('status', 'posted')
            ->whereNotNull('pic_id')
            ->selectRaw(
                'pic_id, COUNT(*) AS cnt, '
                . "COALESCE(SUM(CASE WHEN subtype = 'topup' THEN amount ELSE 0 END), 0) AS total_isi, "
                . "COALESCE(SUM(CASE WHEN subtype = 'usage' THEN amount ELSE 0 END), 0) AS total_pakai",
            )
            ->groupBy('pic_id')
            ->get()
            ->keyBy('pic_id');

        // Bulan berjalan — yang ditampilkan sebagai Pengisian dan Terpakai.
        $bulanIni = $jumlahkan(
            PettyCashMovement::query()->where('movement_date', '>=', $awalBulan),
        );
        // Sebelum bulan berjalan — itulah Saldo Awal.
        $sebelumnya = $jumlahkan(
            PettyCashMovement::query()->where('movement_date', '<', $awalBulan),
        );

        $total = 0.0;
        $rows = $pics->map(function (PettyCashPic $pic) use ($bulanIni, $sebelumnya, &$total) {
            $b = $bulanIni->get($pic->id);
            $isi = (float) ($b->total_isi ?? 0);
            $pakai = (float) ($b->total_pakai ?? 0);

            $l = $sebelumnya->get($pic->id);
            $saldoAwal = (float) ($l->total_isi ?? 0) - (float) ($l->total_pakai ?? 0);

            // Saldo saat ini tetap saldo SEJAK AWAL, supaya tetap cocok
            // dengan kartu di Kas & Bank dan saldo akun 1110 di jurnal
            // (FV-60 poin 6) — dan dengan sendirinya sama dengan
            // saldo awal + pengisian − terpakai.
            $saldo = $saldoAwal + $isi - $pakai;
            $total += $saldo;

            return [
                'id' => $pic->id,
                'name' => $pic->name,
                'saldo_awal' => number_format($saldoAwal, 2, '.', ''),
                'saldo' => number_format($saldo, 2, '.', ''),
                'total_isi' => number_format($isi, 2, '.', ''),
                'total_pakai' => number_format($pakai, 2, '.', ''),
                'count' => (int) ($b->cnt ?? 0),
                // Periode yang diwakili keempat angka di atas.
                'periode' => now()->startOfMonth()->toDateString(),
            ];
        })->values();

        return response()->json([
            'pics' => $rows,
            'total' => number_format($total, 2, '.', ''),
        ]);
    }

    /** Kategori Biaya untuk penggunaan kas kecil — master yang sama dengan Input Pengeluaran (FV-82). */
    public function categories(): JsonResponse
    {
        return response()->json(
            TransactionCategory::query()
                ->where('type', TransactionCategory::TYPE_PENGELUARAN)
                ->where('active', true)
                ->orderBy('name')
                ->get(['code', 'name', 'default_debit_code'])
                ->map(fn (TransactionCategory $c) => [
                    'key' => $c->code,
                    'label' => $c->name,
                    'default_counterpart' => $c->default_debit_code,
                ]),
        );
    }

    private function labelKategori(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        static $cache = [];

        return $cache[$code] ??= TransactionCategory::query()
            ->where('type', TransactionCategory::TYPE_PENGELUARAN)
            ->where('code', $code)
            ->value('name') ?? $code;
    }

    /**
     * Kas Kecil per PIC: topup (isi dari bank/kas tunai) atau usage
     * (pemakaian). Akun kas kecil selalu 1110; lawannya: topup -> coa
     * rekening sumber (atau 1115 kas penerimaan), usage -> akun Nama
     * Pengeluaran / Kategori Biaya dari master.
     */
    public function store(Request $request): JsonResponse
    {
        $viharaId = ViharaScopeService::creationViharaId($request);
        $isian = $this->isian($request, (int) $viharaId, null);
        if ($isian instanceof JsonResponse) {
            return $isian;
        }

        $movement = PettyCashMovement::create($isian + [
            'vihara_id' => $viharaId,
            'cash_account_code' => '1110',
            'status' => 'draft',
            'created_by_user_id' => $request->user()->id,
        ]);

        return response()->json($this->muat($movement), 201);
    }

    /**
     * PUT /petty-cash/{id} — ubah DRAFT (FV-81). Pemeriksaan sama dengan
     * saat membuat; tipe (pengisian/penggunaan) dan viharanya tetap.
     */
    public function update(Request $request, PettyCashMovement $pettyCashMovement): JsonResponse
    {
        ViharaScopeService::guardRecord($request, $pettyCashMovement->vihara_id);
        if ($pettyCashMovement->status !== 'draft') {
            return response()->json(['message' => 'Hanya mutasi draft yang bisa diubah.'], 422);
        }
        $request->merge(['subtype' => $pettyCashMovement->subtype]);

        $isian = $this->isian($request, (int) $pettyCashMovement->vihara_id, $pettyCashMovement);
        if ($isian instanceof JsonResponse) {
            return $isian;
        }

        $pettyCashMovement->update($isian);

        return response()->json($this->muat($pettyCashMovement->fresh()));
    }

    /**
     * Validasi + susun kolom mutasi, dipakai store dan update.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function isian(Request $request, int $viharaId, ?PettyCashMovement $lama): array|JsonResponse
    {
        // Kode kategori lama (operasional, bhikkhu, ...) diterjemahkan ke kode
        // master, akunnya sama persis (FV-82).
        if (is_string($kat = $request->input('category')) && isset(PettyCashMovement::KATEGORI_LAMA[$kat])) {
            $request->merge(['category' => PettyCashMovement::KATEGORI_LAMA[$kat]]);
        }

        $data = $request->validate([
            'date' => ['required', 'date'],
            'pic_id' => ['required', 'integer', 'exists:petty_cash_pics,id'],
            'subtype' => ['required', 'in:topup,usage'],
            'amount' => ['required', 'numeric', 'gt:0'],
            // Sumber dana topup; null = dari kas penerimaan langsung (1115).
            'source_bank_account_id' => [
                'sometimes', 'nullable', 'integer', 'exists:vihara_bank_accounts,id',
                'prohibited_if:subtype,usage',
            ],
            // Kategori Biaya & Nama Pengeluaran dari master, WAJIB untuk
            // pemakaian (FV-58, FV-82): akun beban jurnalnya diambil dari sini.
            'category' => [
                'required_if:subtype,usage', 'prohibited_if:subtype,topup', 'nullable',
                Rule::exists('transaction_categories', 'code')
                    ->where('type', TransactionCategory::TYPE_PENGELUARAN)
                    ->where('active', true),
            ],
            // Nama wajib bila kategorinya punya pilihan nama di master untuk
            // vihara ini (FV-79, FV-82); Biaya Lainnya dkk. tidak punya.
            'name' => [
                Rule::requiredIf(fn () => $request->input('subtype') === 'usage'
                    && TransactionName::adaUntuk('pengeluaran', $request->input('category'), $viharaId)),
                'prohibited_if:subtype,topup', 'nullable', 'string', 'max:255',
                Rule::exists('transaction_names', 'name')
                    ->where('type', 'pengeluaran')
                    ->where('category', (string) $request->input('category'))
                    ->where('active', true),
            ],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'entity_id' => ['sometimes', 'nullable', 'exists:entities,id'],
        ], [
            'category.required_if' => 'Kategori biaya wajib dipilih.',
            'category.exists' => 'Kategori biaya tidak ada di master.',
            'name.required' => 'Nama pengeluaran wajib dipilih.',
            'name.exists' => 'Nama pengeluaran tidak ada di master untuk kategori ini.',
            'notes.max' => 'Catatan maksimal 500 karakter.',
        ]);

        $pic = PettyCashPic::find($data['pic_id']);
        if ((int) $pic->vihara_id !== $viharaId) {
            return response()->json([
                'message' => 'PIC kas kecil bukan milik vihara transaksi ini.',
                'errors' => ['pic_id' => ['PIC kas kecil bukan milik vihara transaksi ini.']],
            ], 422);
        }

        $usage = $data['subtype'] === 'usage';
        if ($usage && ! empty($data['name']) && ! TransactionName::usableForVihara('pengeluaran', $data['name'], $viharaId)) {
            $pesan = "Nama Pengeluaran '{$data['name']}' tidak berlaku untuk vihara ini.";

            return response()->json(['message' => $pesan, 'errors' => ['name' => [$pesan]]], 422);
        }

        // Akun beban pemakaian MENGIKUTI master (FV-58, FV-82): akun Nama
        // Pengeluaran bila punya, selain itu akun bawaan Kategori Biaya -
        // sama dengan Input Pengeluaran. Beban program yang dibayar lewat kas
        // kecil tetap mendarat di akun programnya, bukan Beban Lainnya.
        $counterpart = $usage
            ? (TransactionName::akunDebit($data['category'], $data['name'] ?? null) ?? '6300')
            : '1115';
        if (! empty($data['source_bank_account_id'])) {
            $bankAccount = ViharaBankAccount::find($data['source_bank_account_id']);
            if (! $bankAccount->bolehDipakaiVihara($viharaId)) {
                return response()->json([
                    'message' => 'Rekening ini tidak boleh dipakai vihara transaksi ini.',
                    'errors' => ['source_bank_account_id' => ['Rekening ini tidak boleh dipakai vihara transaksi ini.']],
                ], 422);
            }
            $counterpart = $bankAccount->coa_account_code;
        }

        $entityId = $data['entity_id']
            ?? $lama?->entity_id
            ?? Vihara::query()->whereKey($viharaId)->value('entity_id')
            ?? $request->user()->entity_id
            ?? \App\Models\Entity::query()->value('id');

        PeriodLockService::guard($entityId, $data['date'], 'date');

        return [
            'entity_id' => $entityId,
            'pic_id' => $pic->id,
            'subtype' => $data['subtype'],
            'source_bank_account_id' => $data['source_bank_account_id'] ?? null,
            'type' => $usage ? 'expense' : 'refill',
            'movement_date' => $data['date'],
            'amount' => $data['amount'],
            'counterpart_account_code' => $counterpart,
            'category' => $usage ? $data['category'] : null,
            'name' => $usage ? ($data['name'] ?? null) : null,
            'recipient' => $pic->name,
            'reference' => $data['reference'] ?? null,
            'description' => $data['description'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function muat(PettyCashMovement $m): PettyCashMovement
    {
        return $m->load([
            'entity:id,code,legal_name', 'vihara:id,code,name', 'pic:id,name',
            'sourceBankAccount:id,bank_name,account_number,type',
            'cashAccount:code,name', 'counterpartAccount:code,name',
            'createdBy:id,name', 'voidedBy:id,name',
            'journalEntry:id,description,posted_at,posted_by_user_id',
            'journalEntry.postedBy:id,name',
        ])->setAttribute('category_label', $this->labelKategori($m->category));
    }

    public function show(Request $request, PettyCashMovement $pettyCashMovement): JsonResponse
    {
        if ($pettyCashMovement->vihara_id !== null) {
            ViharaScopeService::guardRecord($request, $pettyCashMovement->vihara_id);
        }

        return response()->json($this->muat($pettyCashMovement));
    }

    public function post(Request $request, PettyCashMovement $pettyCashMovement): JsonResponse
    {
        // Persetujuan mengikuti jangkauan vihara penyetuju, sama dengan modul
        // lain - izin petty_cash.approve saja tidak cukup untuk vihara lain.
        ViharaScopeService::guardRecord($request, $pettyCashMovement->vihara_id);
        if ($pettyCashMovement->status !== 'draft') {
            return response()->json(['message' => 'Mutasi tidak dalam status draft.'], 422);
        }
        PeriodLockService::guard($pettyCashMovement->entity_id, $pettyCashMovement->movement_date, 'date');

        // Satu sumber logika jurnal: model.
        $pettyCashMovement->loadMissing(['pic:id,name', 'sourceBankAccount']);
        $pettyCashMovement->runPostAction($request->user());

        return response()->json($this->muat($pettyCashMovement->fresh()));
    }

    /**
     * POST .../{id}/void — batalkan transaksi yang sudah terposting.
     *
     * Bukan penghapusan: VoidService menulis jurnal pembalik dan meninggalkan
     * jejak siapa/kapan/alasan. Lihat layanan itu untuk alasan tanggal
     * pembalik mengikuti tanggal asli, bukan hari ini.
     */
    public function void(Request $request, PettyCashMovement $pettyCashMovement): JsonResponse
    {
        ViharaScopeService::guardRecord($request, $pettyCashMovement->vihara_id);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'reason.required' => 'Alasan pembatalan wajib diisi.',
            'reason.min' => 'Alasan pembatalan terlalu singkat.',
        ]);

        VoidService::void($pettyCashMovement, $request->user(), $data['reason'], 'Mutasi kas kecil');

        return response()->json($pettyCashMovement->fresh());
    }

    public function destroy(Request $request, PettyCashMovement $pettyCashMovement): JsonResponse
    {
        // Draft milik vihara lain bukan urusan pengguna ini. Tanpa ini,
        // siapa pun yang boleh mencatat di viharanya sendiri bisa
        // menghapus draft vihara lain hanya dengan menebak id.
        ViharaScopeService::guardRecord($request, $pettyCashMovement->vihara_id);

        if ($pettyCashMovement->status !== 'draft') {
            return response()->json(['message' => 'Hanya movement draft yang bisa dihapus.'], 422);
        }
        if ($pettyCashMovement->attachment_path) {
            Storage::disk('local')->delete($pettyCashMovement->attachment_path);
        }
        $pettyCashMovement->delete();

        return response()->json(['message' => 'deleted']);
    }

    /** Upload nota/struk (satu file per movement; upload baru menggantikan lama). */
    public function uploadAttachment(Request $request, PettyCashMovement $pettyCashMovement): JsonResponse
    {
        // Lampiran mengikuti pemilik barisnya. Tanpa penjagaan ini, izin
        // *.view berlaku lintas vihara: siapa pun yang boleh melihat
        // modulnya bisa mengunduh bukti milik vihara lain hanya dengan
        // menebak id — padahal show() baris yang sama menolak 403.
        ViharaScopeService::guardRecord($request, $pettyCashMovement->vihara_id);

        // Lampiran hanya bisa dipasang/diganti selama draft (FV-83): mutasi
        // terposting sudah jadi bukti pembukuan dan tidak boleh berubah.
        if ($pettyCashMovement->status !== 'draft') {
            return response()->json(['message' => 'Lampiran hanya bisa diganti selama mutasi masih draft.'], 422);
        }
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ], [
            'file.max' => 'Lampiran maksimal 10 MB.',
            'file.mimes' => 'Lampiran harus berupa PDF, JPG, JPEG, atau PNG.',
        ]);

        if ($pettyCashMovement->attachment_path) {
            Storage::disk('local')->delete($pettyCashMovement->attachment_path);
        }

        $path = $request->file('file')->store("petty-cash/{$pettyCashMovement->id}", 'local');
        $pettyCashMovement->update(['attachment_path' => $path]);

        return response()->json($this->muat($pettyCashMovement->fresh()), 201);
    }

    public function downloadAttachment(Request $request, PettyCashMovement $pettyCashMovement): StreamedResponse
    {
        // Lampiran mengikuti pemilik barisnya. Tanpa penjagaan ini, izin
        // *.view berlaku lintas vihara: siapa pun yang boleh melihat
        // modulnya bisa mengunduh bukti milik vihara lain hanya dengan
        // menebak id — padahal show() baris yang sama menolak 403.
        ViharaScopeService::guardRecord($request, $pettyCashMovement->vihara_id);

        if (! $pettyCashMovement->attachment_path || ! Storage::disk('local')->exists($pettyCashMovement->attachment_path)) {
            abort(404, 'Tidak ada nota untuk movement ini.');
        }

        return Storage::disk('local')->response($pettyCashMovement->attachment_path);
    }
}
