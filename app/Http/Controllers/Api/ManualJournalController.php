<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\VoidService;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\PeriodLockService;
use App\Services\ViharaScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManualJournalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = JournalEntry::query()
            ->with([
                'entity:id,code',
                'vihara:id,code,name',
                'createdBy:id,name',
                'postedBy:id,name',
                // Kolom "Akun (COA)" di list Jurnal & Buku Besar.
                'lines:id,journal_entry_id,account_code,debit,credit',
                'lines.account:code,name',
            ])
            ->orderByDesc('entry_date')
            ->orderByDesc('id');

        // Daftar jurnal mengikuti jangkauan akses, sama seperti modul lain.
        // Tanpa ini pemegang scope vihara melihat jurnal vihara lain —
        // termasuk jurnal otomatis dari pembayaran/penerimaan mereka, yang
        // berarti nominal dan keterangan transaksinya ikut terbaca.
        // includeYayasanLevel: jurnal tingkat yayasan (vihara_id NULL) tetap
        // terlihat oleh pemegang scope yayasan ke atas.
        ViharaScopeService::applyScope($q, $request, 'vihara_id', true);

        // Sumber jurnal: 'manual' atau jurnal otomatis (pembayaran,
        // penerimaan, petty_cash). Tanpa filter = tampilkan semua.
        if ($source = $request->string('source')->toString()) {
            $q->where('reference_type', $source);
        }
        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }
        if ($entityId = $request->integer('entity_id')) {
            $q->where('entity_id', $entityId);
        }
        if ($from = $request->string('from')->toString()) {
            $q->whereDate('entry_date', '>=', $from);
        }
        if ($to = $request->string('to')->toString()) {
            $q->whereDate('entry_date', '<=', $to);
        }
        if ($search = $request->string('q')->toString()) {
            // ILIKE: pencarian tidak boleh membedakan huruf besar/kecil (FV-27).
            // Nomor dokumen sumber ("PAY-2611175") ikut tertulis di deskripsi
            // jurnalnya, jadi satu kolom ini sudah mencakup nomor dan keterangan.
            $like = '%' . $search . '%';
            $q->where(function ($w) use ($like) {
                $w->where('description', 'ilike', $like)
                    ->orWhere('reference', 'ilike', $like);
            });
        }

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));
        $halaman = $q->paginate($perPage);

        // Lampirkan ringkasan transaksi SUMBER tiap jurnal.
        //
        // Deskripsi tersimpan hanya memuat kode + nama kategori ("Pembayaran
        // PAY-2611003 — Biaya Operasional Vihara"), yang sama persis untuk
        // setiap biaya operasional — tidak bisa dipakai membedakan satu
        // transaksi dari yang lain. Informasi pembedanya diambil dari baris
        // sumbernya, bukan dari teks itu, supaya jurnal LAMA pun ikut
        // terbantu tanpa menulis ulang catatan buku besar.
        $halaman->through(fn ($j) => $j->setAttribute('source', $this->ringkasanSumber($j)));

        return response()->json($halaman);
    }

    public function show(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        // Detail jurnal vihara lain ditolak, bukan sekadar tidak
        // tercantum di daftar.
        ViharaScopeService::guardRecord($request, $journalEntry->vihara_id, $journalEntry->entity_id);

        // Viewable for any source (manual + auto-posted). Deletion stays
        // manual-only (see destroy()).
        $journalEntry->load([
            'entity:id,code,legal_name', 'vihara:id,code,name', 'lines', 'lines.account:code,name',
            'createdBy:id,name', 'postedBy:id,name', 'submittedBy:id,name', 'rejectedBy:id,name',
        ]);

        // Ringkasan transaksi sumbernya ikut dikirim — detail dan daftar
        // jadi menyebut hal yang sama (FV-51 poin 3).
        $journalEntry->setAttribute('source', $this->ringkasanSumber($journalEntry));

        return response()->json($journalEntry);
    }

    /**
     * Ringkasan transaksi sumber sebuah jurnal, untuk ditampilkan di daftar.
     * null bila jurnal manual atau sumbernya sudah tidak ada.
     */
    private function ringkasanSumber(JournalEntry $j): ?array
    {
        $tipe = $j->reference_type;
        if ($tipe === null || $tipe === 'manual' || $j->reference_id === null) {
            return null;
        }

        // Jurnal pembalik menunjuk dokumen yang sama dengan aslinya.
        $pembalik = str_ends_with($tipe, '_void');
        $dasar = $pembalik ? substr($tipe, 0, -5) : $tipe;

        $peta = [
            'pembayaran' => [\App\Models\Pembayaran::class, 'Pengeluaran'],
            'penerimaan' => [\App\Models\Penerimaan::class, 'Penerimaan'],
            'petty_cash' => [\App\Models\PettyCashMovement::class, 'Kas Kecil'],
        ];
        if (! isset($peta[$dasar])) {
            return null;
        }

        [$kelas, $label] = $peta[$dasar];
        $row = $kelas::find($j->reference_id);
        if (! $row) {
            return null;
        }

        return [
            'type' => $dasar,
            'label' => $pembalik ? $label . ' (pembalik)' : $label,
            'code' => $row->code ?? null,
            'name' => $row->name ?? null,
            'counterparty' => $row->payee_name ?? $row->source_name ?? null,
            'reference' => $row->reference ?? null,
            // Catatan penginput: ditampilkan sebagai keterangan TERPISAH,
            // tidak lagi menimpa deskripsi baris jurnal (FV-51 poin 2).
            'catatan' => $row->description ?? null,
            'id' => $row->id,
        ];
    }

    /**
     * POST /journal-entries — simpan jurnal manual.
     *
     * Jurnal manual dibuat admin, jadi TIDAK lewat pengajuan/persetujuan
     * (FV-68, mengganti alur FV-53). `posting: true` langsung memposting,
     * hanya untuk pemegang journal.approve ("Posting jurnal manual");
     * tanpa itu jurnal disimpan sebagai draft.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validasiJurnal($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        if ($tolak = $this->tolakPostingTanpaIzin($request)) {
            return $tolak;
        }

        $entry = DB::transaction(function () use ($data, $request) {
            $entry = JournalEntry::create([
                'entity_id' => $data['entity_id'],
                'vihara_id' => $data['vihara_id'],
                'entry_date' => $data['entry_date'],
                'reference_type' => 'manual',
                'reference_id' => null,
                'description' => $data['description'],
                'reference' => $data['reference'] ?? null,
                'total_debit' => $data['total_debit'],
                'total_credit' => $data['total_credit'],
                'status' => 'draft',
                'created_by_user_id' => $request->user()->id,
            ]);
            $this->tulisBaris($entry, $data['lines']);

            if ($request->boolean('posting')) {
                $entry->runPostAction($request->user());
            }

            return $entry;
        });

        return response()->json($entry->fresh()->load('lines', 'entity:id,code', 'vihara:id,code,name'), 201);
    }

    /**
     * PUT /journal-entries/{id} — ubah jurnal manual yang belum diposting,
     * lalu simpan lagi sebagai draft atau langsung posting (FV-68).
     */
    public function update(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $this->pastikanManual($journalEntry);
        ViharaScopeService::guardRecord($request, $journalEntry->vihara_id, $journalEntry->entity_id);

        // submitted/rejected = sisa alur persetujuan FV-53; diperlakukan
        // sama dengan draft supaya tidak ada jurnal yang tersangkut.
        if (! in_array($journalEntry->status, ['draft', 'submitted', 'rejected'], true)) {
            return response()->json(['message' => 'Jurnal yang sudah diposting tidak bisa diubah.'], 422);
        }

        $data = $this->validasiJurnal($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        if ($tolak = $this->tolakPostingTanpaIzin($request)) {
            return $tolak;
        }
        // Periode lama juga dijaga: memindahkan jurnal keluar dari periode
        // yang sudah ditutup sama dengan mengubah periode itu.
        PeriodLockService::guard($journalEntry->entity_id, $journalEntry->entry_date, 'entry_date');

        DB::transaction(function () use ($data, $request, $journalEntry) {
            $journalEntry->forceFill([
                'entity_id' => $data['entity_id'],
                'vihara_id' => $data['vihara_id'],
                'entry_date' => $data['entry_date'],
                'description' => $data['description'],
                'reference' => $data['reference'] ?? null,
                'total_debit' => $data['total_debit'],
                'total_credit' => $data['total_credit'],
                'status' => 'draft',
                'submitted_at' => null,
                'submitted_by_user_id' => null,
                'rejected_at' => null,
                'rejected_by_user_id' => null,
                'reject_reason' => null,
            ])->save();
            $journalEntry->lines()->delete();
            $this->tulisBaris($journalEntry, $data['lines']);

            if ($request->boolean('posting')) {
                $journalEntry->refresh()->runPostAction($request->user());
            }
        });

        return response()->json($journalEntry->fresh()->load('lines', 'entity:id,code', 'vihara:id,code,name'));
    }

    /**
     * POST .../{id}/post — posting draft jurnal manual. Route-nya dijaga
     * journal.approve; tidak perlu diajukan dulu (FV-68).
     */
    public function post(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $this->pastikanManual($journalEntry);
        ViharaScopeService::guardRecord($request, $journalEntry->vihara_id, $journalEntry->entity_id);

        if (! in_array($journalEntry->status, ['draft', 'submitted', 'rejected'], true)) {
            return response()->json([
                'message' => 'Jurnal ini sudah diproses, tidak bisa diposting lagi.',
            ], 422);
        }
        if ($kurang = $this->kelengkapanKurang($journalEntry)) {
            return response()->json(['message' => $kurang], 422);
        }

        PeriodLockService::guard($journalEntry->entity_id, $journalEntry->entry_date, 'entry_date');
        $journalEntry->runPostAction($request->user());

        return response()->json($journalEntry->fresh()->load('lines'));
    }

    /** Jurnal otomatis dari transaksi tidak diurus di sini. */
    private function pastikanManual(JournalEntry $journalEntry): void
    {
        abort_unless($journalEntry->reference_type === 'manual', 404, 'Bukan manual journal.');
    }

    /** `posting: true` tanpa izin posting → 403 (FV-68 poin 7). */
    private function tolakPostingTanpaIzin(Request $request): ?JsonResponse
    {
        if ($request->boolean('posting') && ! $request->user()->can('journal.approve')) {
            return response()->json([
                'message' => 'Anda tidak memiliki izin memposting jurnal manual. Simpan sebagai draft.',
            ], 403);
        }

        return null;
    }

    /**
     * Validasi isi jurnal untuk simpan/ubah: yayasan & vihara dalam
     * jangkauan, periode belum ditutup, akun aktif & bukan header, tiap
     * baris hanya debit ATAU kredit, dan Dr = Kr.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function validasiJurnal(Request $request): array|JsonResponse
    {
        $data = $request->validate([
            'entity_id' => ['required', 'exists:entities,id'],
            // Vihara wajib (FV-64): transaksi pusat yayasan dicatat di unit
            // "Umum/Pusat Yayasan", bukan sebagai jurnal tanpa vihara.
            'vihara_id' => ['sometimes', 'nullable', 'integer', 'exists:viharas,id'],
            'entry_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'posting' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_code' => ['required', 'exists:accounts,code'],
            'lines.*.debit' => ['required', 'numeric', 'min:0'],
            'lines.*.credit' => ['required', 'numeric', 'min:0'],
            'lines.*.description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // Yayasan yang disebut harus yayasan user (FV-34); viharanya sudah
        // dijaga optionalCreationViharaId (FV-35).
        ViharaScopeService::guardEntity($request, (int) $data['entity_id']);
        $data['vihara_id'] = ViharaScopeService::optionalCreationViharaId($request);

        // Satu tempat untuk transaksi pusat yayasan: unit Umum/Pusat (FV-64).
        // Jurnal tanpa vihara dulu muncul sebagai baris "Kantor Yayasan"
        // terpisah dari unit Umum, sehingga angka pusat terpecah dua.
        if ($data['vihara_id'] === null) {
            return response()->json([
                'message' => 'Vihara wajib dipilih.',
                'errors' => ['vihara_id' => ['Vihara wajib dipilih. Transaksi pusat yayasan dicatat di unit Umum/Pusat Yayasan.']],
            ], 422);
        }
        if ((int) \App\Models\Vihara::whereKey($data['vihara_id'])->value('entity_id') !== (int) $data['entity_id']) {
            return response()->json([
                'message' => 'Vihara yang dipilih bukan milik yayasan yang disebut.',
                'errors' => ['vihara_id' => ['Vihara yang dipilih bukan milik yayasan yang disebut.']],
            ], 422);
        }

        PeriodLockService::guard($data['entity_id'], $data['entry_date'], 'entry_date');

        $akun = Account::query()
            ->whereIn('code', array_column($data['lines'], 'account_code'))
            ->get(['code', 'name', 'active', 'is_header'])
            ->keyBy('code');

        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($data['lines'] as $i => $line) {
            $no = $i + 1;
            $a = $akun[$line['account_code']] ?? null;
            if ($a === null || ! $a->active) {
                return response()->json(['message' => "Baris {$no}: akun {$line['account_code']} tidak aktif."], 422);
            }
            if ($a->is_header) {
                return response()->json(['message' => "Baris {$no}: akun {$a->code} {$a->name} adalah akun induk (header), pilih akun turunannya."], 422);
            }
            $d = (float) $line['debit'];
            $c = (float) $line['credit'];
            if ($d > 0 && $c > 0) {
                return response()->json([
                    'message' => "Baris {$no}: debit dan kredit tidak boleh diisi keduanya.",
                ], 422);
            }
            if ($d === 0.0 && $c === 0.0) {
                return response()->json([
                    'message' => "Baris {$no}: isi salah satu debit atau kredit (> 0).",
                ], 422);
            }
            $totalDebit += $d;
            $totalCredit += $c;
        }

        if (abs($totalDebit - $totalCredit) > 0.005) {
            return response()->json([
                'message' => sprintf(
                    'Jurnal tidak balance: Debit %s vs Kredit %s (selisih %s).',
                    number_format($totalDebit, 2),
                    number_format($totalCredit, 2),
                    number_format(abs($totalDebit - $totalCredit), 2),
                ),
            ], 422);
        }

        $data['total_debit'] = $totalDebit;
        $data['total_credit'] = $totalCredit;

        return $data;
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function tulisBaris(JournalEntry $entry, array $lines): void
    {
        foreach ($lines as $line) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_code' => $line['account_code'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'description' => $line['description'] ?? null,
            ]);
        }
    }

    /** @return string|null pesan kekurangan, null kalau sudah lengkap */
    private function kelengkapanKurang(JournalEntry $entry): ?string
    {
        $entry->loadMissing('lines');
        if ($entry->lines->count() < 2) {
            return 'Jurnal harus punya minimal dua baris.';
        }
        $debit = (float) $entry->lines->sum('debit');
        $kredit = (float) $entry->lines->sum('credit');
        if (round($debit - $kredit, 2) !== 0.0) {
            return 'Jurnal belum seimbang: debit dan kredit harus sama.';
        }
        if ($debit <= 0) {
            return 'Nilai jurnal tidak boleh nol.';
        }
        $tidakAktif = Account::query()
            ->whereIn('code', $entry->lines->pluck('account_code'))
            ->where(fn ($q) => $q->where('active', false)->orWhere('is_header', true))
            ->pluck('code');
        if ($tidakAktif->isNotEmpty()) {
            return 'Akun '.$tidakAktif->implode(', ').' tidak aktif atau akun induk. Ubah jurnal dulu.';
        }

        return null;
    }

    /**
     * POST .../{id}/void — batalkan transaksi yang sudah terposting.
     *
     * Bukan penghapusan: VoidService menulis jurnal pembalik dan meninggalkan
     * jejak siapa/kapan/alasan. Lihat layanan itu untuk alasan tanggal
     * pembalik mengikuti tanggal asli, bukan hari ini.
     */
    public function void(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        ViharaScopeService::guardRecord($request, $journalEntry->vihara_id, $journalEntry->entity_id);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'reason.required' => 'Alasan pembatalan wajib diisi.',
            'reason.min' => 'Alasan pembatalan terlalu singkat.',
        ]);

        VoidService::void($journalEntry, $request->user(), $data['reason'], 'Jurnal');

        return response()->json($journalEntry->fresh());
    }

    public function destroy(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        // Draft milik vihara lain bukan urusan pengguna ini. Tanpa ini,
        // siapa pun yang boleh mencatat di viharanya sendiri bisa
        // menghapus draft vihara lain hanya dengan menebak id.
        ViharaScopeService::guardRecord($request, $journalEntry->vihara_id, $journalEntry->entity_id);

        if ($journalEntry->reference_type !== 'manual') {
            abort(404, 'Bukan manual journal.');
        }
        if (! in_array($journalEntry->status, ['draft', 'submitted', 'rejected'], true)) {
            return response()->json(['message' => 'Hanya jurnal yang belum diposting yang bisa dihapus.'], 422);
        }
        $journalEntry->lines()->delete();
        $journalEntry->delete();
        return response()->json(['message' => 'deleted']);
    }
}
