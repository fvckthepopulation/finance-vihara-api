<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VoidService;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Pembayaran;
use App\Models\TransactionCategory;
use App\Models\TransactionName;
use App\Services\PeriodLockService;
use App\Services\ViharaScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PembayaranController extends Controller
{
    use \App\Http\Controllers\Api\Concerns\SiklusPersetujuan;

    public function index(Request $request): JsonResponse
    {
        $q = Pembayaran::query()
            ->with([
                'entity:id,code,legal_name',
                'vihara:id,code,name',
                'bankAccount:id,bank_name,account_number',
                'payee:id,code,name',
                'sourceAccount:code,name',
                'debitAccount:code,name',
            ])
            ->orderByDesc('date')
            ->orderByDesc('id');

        ViharaScopeService::applyScope($q, $request);
        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }
        if ($category = $request->string('category')->toString()) {
            $q->where('category', $category);
        }
        if (preg_match('/^\d{4}-\d{2}$/', $month = $request->string('month')->toString())) {
            $q->whereRaw("to_char(date, 'YYYY-MM') = ?", [$month]);
        }
        if ($entityId = $request->integer('entity_id')) {
            $q->where('entity_id', $entityId);
        }

        return response()->json($q->paginate(min(max((int) $request->integer('per_page', 20), 1), 200)));
    }

    public function show(Request $request, Pembayaran $pembayaran): JsonResponse
    {
        ViharaScopeService::guardRecord($request, $pembayaran->vihara_id);

        return response()->json(
            $pembayaran->load([
                'entity', 'vihara:id,code,name', 'bankAccount:id,bank_name,account_number', 'payee',
                'sourceAccount:code,name', 'debitAccount:code,name',
                'journalEntry.lines',
                // Jejak siklus: siapa mengajukan, menyetujui, atau menolak.
                // Inilah penyetuju yang sah — berbeda dari kolom teks bebas
                // approved_by_name yang dulu diketik penginput sendiri.
                'createdBy:id,name', 'submittedBy:id,name', 'postedBy:id,name', 'rejectedBy:id,name',
            ]),
        );
    }

    public function store(Request $request): JsonResponse
    {
        // Mode draft: pekerjaan setengah jadi boleh disimpan dengan hanya
        // yayasan + vihara. Kelengkapannya ditegakkan saat DIAJUKAN atau
        // DISETUJUI, bukan saat disimpan — lihat Concerns\SiklusPersetujuan.
        $draft = $request->boolean('draft');

        $data = $request->validate([
            'date' => ['required', 'date'],
            'method' => ['required', 'in:transfer,tunai,auto_debit'],
            // Rekening master vihara — kalau dikirim, source di-derive dari
            // coa_account_code rekening (override input client).
            // Transfer/auto-debit WAJIB menyebut rekening sumber: dari situ
            // akun kredit jurnal (1120/1130) diturunkan. Boleh lewat
            // bank_account_id (yang dipakai form) atau source_account_code
            // (kompat lama) — salah satu cukup, tapi tidak boleh dua-duanya
            // kosong. Pesannya ditulis eksplisit karena tanpa itu klien hanya
            // menerima kunci mentah `validation.required` pada field yang
            // bahkan tidak ada di formnya.
            'bank_account_id' => [
                Rule::requiredIf(fn () => ! $draft && $request->input('method') !== 'tunai' && ! $request->filled('source_account_code')),
                'nullable', 'integer', 'exists:vihara_bank_accounts,id',
            ],
            // Tunai default ke 1115 Kas Penerimaan Langsung; transfer/
            // auto_debit wajib pilih rekening (bank_account_id ATAU
            // source_account_code).
            // Jalur kompat lama. Kewajiban "harus ada rekening" sudah
            // ditegakkan di bank_account_id di atas, jadi di sini cukup
            // divalidasi bentuknya — supaya saat dua-duanya kosong pengguna
            // menerima SATU pesan, bukan dua.
            'source_account_code' => ['sometimes', 'nullable', 'exists:accounts,code'],
            'category' => [
                Rule::requiredIf(! $draft),
                'nullable',
                // Kategori diambil dari master (FV-29), bukan konstanta: yang
                // dinonaktifkan admin tidak boleh dipakai transaksi baru.
                Rule::exists('transaction_categories', 'code')
                    ->where('type', TransactionCategory::TYPE_PENGELUARAN)
                    ->where('active', true),
            ],
            'name' => [
                'sometimes', 'nullable', 'string', 'max:255',
                // Nama Pengeluaran harus terdaftar di master, sesuai
                // kategorinya. Pada draft tanpa kategori, pencocokan ini
                // dilewati — belum ada kategori untuk dicocokkan.
                Rule::when($request->filled('category'), [
                    Rule::exists('transaction_names', 'name')
                        ->where('type', 'pengeluaran')
                        ->where('category', (string) $request->input('category')),
                ]),
            ],
            'entity_id' => ['sometimes', 'nullable', 'exists:entities,id'],
            'payee_id' => ['sometimes', 'nullable', 'exists:payees,id'],
            'payee_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'approved_by_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'debit_account_code' => ['sometimes', 'nullable', 'exists:accounts,code'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'amount' => [Rule::requiredIf(! $draft), 'nullable', 'numeric', 'gt:0'],
            'description' => ['sometimes', 'nullable', 'string'],
        ], [
            'bank_account_id.required' => 'Transfer dari Rekening wajib dipilih.',
        ]);

        $data['vihara_id'] = ViharaScopeService::creationViharaId($request);

        // Nama Pengeluaran khusus vihara lain tidak boleh dipakai di sini.
        if (! empty($data['name'])
            && ! \App\Models\TransactionName::usableForVihara('pengeluaran', $data['name'], $data['vihara_id'])) {
            return response()->json([
                'message' => "Nama Pengeluaran '{$data['name']}' tidak berlaku untuk vihara ini.",
                'errors' => ['name' => ["Nama Pengeluaran '{$data['name']}' tidak berlaku untuk vihara ini."]],
            ], 422);
        }

        // Rekening bank master: wajib milik vihara transaksi; akun sumber JE
        // mengikuti coa_account_code rekening.
        if (! empty($data['bank_account_id'])) {
            $bankAccount = \App\Models\ViharaBankAccount::find($data['bank_account_id']);
            // Rekening milik yayasan dan dipakai bersama (FV-36): yang
            // diperiksa bukan "milik vihara ini", melainkan "boleh dipakai
            // vihara ini".
            if (! $bankAccount->bolehDipakaiVihara((int) $data['vihara_id'])) {
                return response()->json([
                    'message' => 'Rekening ini tidak boleh dipakai vihara transaksi ini.',
                    'errors' => ['bank_account_id' => ['Rekening ini tidak boleh dipakai vihara transaksi ini.']],
                ], 422);
            }
            $data['source_account_code'] = $bankAccount->coa_account_code;
        }

        // Yayasan MENGIKUTI vihara transaksi, bukan yayasan si pengguna.
        // Tanpa ini, admin lintas yayasan yang mencatat untuk vihara di
        // yayasan lain menyimpan baris ber-entity_id miliknya sendiri — dan
        // PeriodLockService di bawah pun memeriksa kunci periode yayasan yang
        // keliru. Vihara adalah sumber kebenarannya; entity_id dari klien
        // sengaja diabaikan bila bertentangan.
        $viharaEntityId = \App\Models\Vihara::whereKey($data['vihara_id'])->value('entity_id');
        if ($viharaEntityId !== null) {
            // Yayasan yang bertentangan dengan viharanya ditolak, bukan
            // ditimpa diam-diam (C-PNR-09).
            if (! empty($data['entity_id']) && (int) $data['entity_id'] !== (int) $viharaEntityId) {
                return response()->json([
                    'message' => 'Vihara yang dipilih bukan milik yayasan yang disebut.',
                    'errors' => ['vihara_id' => ['Vihara yang dipilih bukan milik yayasan yang disebut.']],
                ], 422);
            }
            $data['entity_id'] = (int) $viharaEntityId;
        } elseif (empty($data['entity_id'])) {
            // Vihara lama yang belum terhubung ke yayasan mana pun.
            $data['entity_id'] = $request->user()->entity_id
                ?? \App\Models\Entity::query()->value('id');
        }
        if (empty($data['source_account_code']) && ! $draft) {
            $data['source_account_code'] = '1115'; // Kas Penerimaan Langsung — hanya tercapai saat method tunai
        }

        PeriodLockService::guard($data['entity_id'], $data['date'], 'date');

        if (empty($data['debit_account_code']) && ! empty($data['category'])) {
            // Akun beban mengikuti Nama Pengeluaran yang dipilih (FV-29);
            // kalau nama itu belum punya akun sendiri, dipakai akun bawaan
            // kategorinya dari master.
            $data['debit_account_code'] = TransactionName::akunDebit(
                $data['category'],
                $data['name'] ?? null,
            );
        }
        if (! empty($data['source_account_code'])
            && $data['source_account_code'] === ($data['debit_account_code'] ?? null)) {
            return response()->json([
                'message' => 'Source account dan debit account tidak boleh sama.',
            ], 422);
        }

        $pembayaran = Pembayaran::create($data + [
            'code' => $this->nextCode(),
            'status' => 'draft',
            'created_by_user_id' => $request->user()->id,
        ]);

        return response()->json(
            $pembayaran->load(['entity', 'vihara:id,code,name', 'bankAccount:id,bank_name,account_number', 'payee', 'sourceAccount:code,name', 'debitAccount:code,name']),
            201,
        );
    }

    public function post(Request $request, Pembayaran $pembayaran): JsonResponse
    {
        ViharaScopeService::guardRecord($request, $pembayaran->vihara_id);

        // Dua jalur sah menuju posted: lewat antrean (submitted), atau
        // langsung dari draft oleh pengguna yang memang berhak menyetujui —
        // itulah tombol "Submit Pengeluaran". Yang sudah posted atau
        // dibatalkan tidak boleh diposting ulang.
        if (! in_array($pembayaran->status, ['draft', 'submitted'], true)) {
            return response()->json([
                'message' => 'Pembayaran ini sudah diproses, tidak bisa diposting lagi.',
            ], 422);
        }

        $kurang = $this->kelengkapanKurang($pembayaran);
        if ($kurang) {
            return response()->json([
                'message' => 'Lengkapi dulu: ' . implode(', ', $kurang) . '.',
            ], 422);
        }

        DB::transaction(function () use ($pembayaran, $request) {
            $desc = "Pembayaran {$pembayaran->code} — "
                . (TransactionCategory::labelPengeluaran($pembayaran->category) ?? $pembayaran->category);

            $journal = JournalEntry::create([
                'entity_id' => $pembayaran->entity_id,
                'vihara_id' => $pembayaran->vihara_id,
                'entry_date' => $pembayaran->date,
                'reference_type' => 'pembayaran',
                'reference_id' => $pembayaran->id,
                'description' => $desc,
                'total_debit' => $pembayaran->amount,
                'total_credit' => $pembayaran->amount,
                'posted_at' => now(),
                'posted_by_user_id' => $request->user()->id,
            ]);

            JournalLine::create([
                'journal_entry_id' => $journal->id,
                'account_code' => $pembayaran->debit_account_code,
                'debit' => $pembayaran->amount,
                'credit' => 0,
                'description' => $pembayaran->description ?? $desc,
            ]);
            JournalLine::create([
                'journal_entry_id' => $journal->id,
                'account_code' => $pembayaran->source_account_code,
                'debit' => 0,
                'credit' => $pembayaran->amount,
                'description' => $pembayaran->description ?? $desc,
            ]);

            $pembayaran->update([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by_user_id' => $request->user()->id,
                'journal_entry_id' => $journal->id,
            ]);
        });

        return response()->json($pembayaran->fresh()->load('journalEntry.lines'));
    }

    /**
     * POST .../{id}/void — batalkan transaksi yang sudah terposting.
     *
     * Bukan penghapusan: VoidService menulis jurnal pembalik dan meninggalkan
     * jejak siapa/kapan/alasan. Lihat layanan itu untuk alasan tanggal
     * pembalik mengikuti tanggal asli, bukan hari ini.
     */
    public function void(Request $request, Pembayaran $pembayaran): JsonResponse
    {
        ViharaScopeService::guardRecord($request, $pembayaran->vihara_id);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'reason.required' => 'Alasan pembatalan wajib diisi.',
            'reason.min' => 'Alasan pembatalan terlalu singkat.',
        ]);

        VoidService::void($pembayaran, $request->user(), $data['reason'], 'Pembayaran');

        return response()->json($pembayaran->fresh());
    }

    /** Kolom yang wajib terisi sebelum sebuah pengeluaran boleh naik. */
    private const WAJIB_LENGKAP = [
        'amount' => 'Jumlah',
        'category' => 'Kategori Biaya',
        'source_account_code' => 'Transfer dari Rekening',
        'debit_account_code' => 'Akun debet',
    ];

    /** @return string[] nama field yang masih kosong */
    private function kelengkapanKurang(Pembayaran $p): array
    {
        $kurang = [];
        foreach (self::WAJIB_LENGKAP as $kolom => $label) {
            if ($p->{$kolom} === null || $p->{$kolom} === '' || $p->{$kolom} == 0) {
                $kurang[] = $label;
            }
        }

        return $kurang;
    }

    /**
     * PATCH /api/pembayaran/{id} — ubah draft yang belum diajukan.
     *
     * Hanya draft dan yang ditolak: begitu masuk antrean atau terposting,
     * isinya tidak boleh berubah di belakang approver.
     */
    public function update(Request $request, Pembayaran $pembayaran): JsonResponse
    {
        ViharaScopeService::guardRecord($request, $pembayaran->vihara_id);
        $this->guardPemilikDraft($request, $pembayaran, 'pengeluaran');

        if (! in_array($pembayaran->status, ['draft', 'rejected'], true)) {
            return response()->json([
                'message' => 'Hanya pengeluaran berstatus draft atau ditolak yang bisa diubah.',
            ], 422);
        }

        $data = $request->validate([
            'date' => ['sometimes', 'date'],
            'bank_account_id' => ['sometimes', 'nullable', 'integer', 'exists:vihara_bank_accounts,id'],
            'category' => [
                'sometimes', 'nullable',
                Rule::exists('transaction_categories', 'code')
                    ->where('type', TransactionCategory::TYPE_PENGELUARAN)
                    ->where('active', true),
            ],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payee_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Penerima dari master (FV-31) — ikut bisa diubah, kalau tidak
            // rekap per penerima jadi salah begitu draft disunting.
            'payee_id' => ['sometimes', 'nullable', 'integer', 'exists:payees,id'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        // Nama Pengeluaran yang dikirim harus ada di master DAN berlaku untuk
        // vihara baris ini — aturan yang sama dengan store(). Tanpa ini,
        // penyuntingan draft jadi pintu belakang untuk nama khusus vihara
        // lain, atau nama yang sudah dihapus dari master.
        if (! empty($data['name'])) {
            $kategori = $data['category'] ?? $pembayaran->category;
            $adaDiMaster = TransactionName::query()
                ->where('type', 'pengeluaran')
                ->where('name', $data['name'])
                ->when($kategori, fn ($q) => $q->where('category', $kategori))
                ->exists();
            if (! $adaDiMaster
                || ! TransactionName::usableForVihara('pengeluaran', $data['name'], (int) $pembayaran->vihara_id)) {
                return response()->json([
                    'message' => "Nama Pengeluaran '{$data['name']}' tidak berlaku untuk vihara ini.",
                    'errors' => ['name' => ["Nama Pengeluaran '{$data['name']}' tidak berlaku untuk vihara ini."]],
                ], 422);
            }
        }

        if (array_key_exists('bank_account_id', $data) && ! empty($data['bank_account_id'])) {
            $rek = \App\Models\ViharaBankAccount::find($data['bank_account_id']);
            if (! $rek->bolehDipakaiVihara((int) $pembayaran->vihara_id)) {
                return response()->json([
                    'message' => 'Rekening ini tidak boleh dipakai vihara transaksi ini.',
                ], 422);
            }
            $data['source_account_code'] = $rek->coa_account_code;
        }
        if (! empty($data['category'])) {
            $data['debit_account_code'] = TransactionName::akunDebit(
                $data['category'],
                $data['name'] ?? $pembayaran->name,
            );
        }

        $pembayaran->fill($data)->save();

        return response()->json($pembayaran->fresh());
    }

    /** POST /api/pembayaran/{id}/submit — ajukan draft ke persetujuan. */
    public function submit(Request $request, Pembayaran $pembayaran): JsonResponse
    {
        return $this->ajukan($request, $pembayaran, 'pengeluaran', self::WAJIB_LENGKAP);
    }

    /** POST /api/pembayaran/{id}/reject — tolak pengajuan, dengan alasan. */
    public function reject(Request $request, Pembayaran $pembayaran): JsonResponse
    {
        return $this->tolak($request, $pembayaran, 'pengeluaran');
    }

    public function destroy(Request $request, Pembayaran $pembayaran): JsonResponse
    {
        // Draft milik vihara lain bukan urusan pengguna ini. Tanpa ini,
        // siapa pun yang boleh mencatat di viharanya sendiri bisa
        // menghapus draft vihara lain hanya dengan menebak id.
        ViharaScopeService::guardRecord($request, $pembayaran->vihara_id);

        // Pencatat membuang draftnya SENDIRI. Approver tidak menghapus draft
        // orang lain: yang sudah diajukan ditolak lewat /reject supaya
        // pembuatnya tahu alasannya, dan yang masih draft belum pernah
        // dilihat approver (FV-44 poin 5).
        $u = $request->user();
        if (! $u->can('pembayaran.create') && ! $u->hasRole('superadmin')) {
            abort(403, 'Anda tidak berhak menghapus draft pembayaran.');
        }
        $this->guardPemilikDraft($request, $pembayaran, 'pengeluaran');

        if ($pembayaran->status !== 'draft') {
            return response()->json(['message' => 'Hanya pembayaran draft yang bisa dihapus.'], 422);
        }
        if ($pembayaran->attachment_path) {
            Storage::disk('local')->delete($pembayaran->attachment_path);
        }
        $pembayaran->delete();

        return response()->json(['message' => 'deleted']);
    }

    public function uploadAttachment(Request $request, Pembayaran $pembayaran): JsonResponse
    {
        // Lampiran mengikuti pemilik barisnya. Tanpa penjagaan ini, izin
        // *.view berlaku lintas vihara: siapa pun yang boleh melihat
        // modulnya bisa mengunduh bukti milik vihara lain hanya dengan
        // menebak id — padahal show() baris yang sama menolak 403.
        ViharaScopeService::guardRecord($request, $pembayaran->vihara_id);

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);
        if ($pembayaran->attachment_path) {
            Storage::disk('local')->delete($pembayaran->attachment_path);
        }
        $path = $request->file('file')->store("pembayaran/{$pembayaran->id}", 'local');
        $pembayaran->update(['attachment_path' => $path]);

        return response()->json($pembayaran->fresh(), 201);
    }

    public function downloadAttachment(Request $request, Pembayaran $pembayaran): StreamedResponse
    {
        // Lampiran mengikuti pemilik barisnya. Tanpa penjagaan ini, izin
        // *.view berlaku lintas vihara: siapa pun yang boleh melihat
        // modulnya bisa mengunduh bukti milik vihara lain hanya dengan
        // menebak id — padahal show() baris yang sama menolak 403.
        ViharaScopeService::guardRecord($request, $pembayaran->vihara_id);

        if (! $pembayaran->attachment_path || ! Storage::disk('local')->exists($pembayaran->attachment_path)) {
            abort(404, 'Tidak ada lampiran untuk pembayaran ini.');
        }
        return Storage::disk('local')->response($pembayaran->attachment_path);
    }

    /**
     * Pilihan Kategori Biaya untuk form. Sumbernya master (FV-29) dan hanya
     * yang aktif — kategori yang dinonaktifkan admin langsung hilang dari
     * dropdown tanpa menyentuh transaksi lama.
     */
    public function categories(): JsonResponse
    {
        $rows = TransactionCategory::query()
            ->where('type', TransactionCategory::TYPE_PENGELUARAN)
            ->where('active', true)
            ->orderBy('name')
            ->get(['code', 'name', 'default_debit_code'])
            ->map(fn (TransactionCategory $c) => [
                'key' => $c->code,
                'label' => $c->name,
                'default_debit' => $c->default_debit_code,
            ]);

        return response()->json($rows);
    }

    private function nextCode(): string
    {
        $prefix = 'PAY-' . now()->format('ym');
        $last = Pembayaran::query()->where('code', 'like', "$prefix%")->orderByDesc('id')->value('code');
        $n = $last ? ((int) substr($last, -3)) + 1 : 1;
        return $prefix . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }
}
