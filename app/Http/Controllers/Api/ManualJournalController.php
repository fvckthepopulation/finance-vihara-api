<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        ViharaScopeService::guardRecord($request, $journalEntry->vihara_id);

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

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_id' => ['required', 'exists:entities,id'],
            // Dimensi vihara opsional — user ter-scope dipaksa ke vihara-nya;
            // superadmin boleh kosong (= jurnal level yayasan).
            'vihara_id' => ['sometimes', 'nullable', 'integer', 'exists:viharas,id'],
            'entry_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_code' => ['required', 'exists:accounts,code'],
            'lines.*.debit' => ['required', 'numeric', 'min:0'],
            'lines.*.credit' => ['required', 'numeric', 'min:0'],
            'lines.*.description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // Yayasan yang disebut harus yayasan user (FV-34); viharanya sudah
        // dijaga optionalCreationViharaId (FV-35).
        ViharaScopeService::guardEntity($request, (int) $data['entity_id']);

        $viharaId = ViharaScopeService::optionalCreationViharaId($request);

        PeriodLockService::guard($data['entity_id'], $data['entry_date'], 'entry_date');

        // Validate D = C and each line has exactly one of debit/credit
        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($data['lines'] as $i => $line) {
            $d = (float) $line['debit'];
            $c = (float) $line['credit'];
            if ($d > 0 && $c > 0) {
                return response()->json([
                    'message' => "Baris " . ($i + 1) . ": debit dan kredit tidak boleh diisi keduanya.",
                ], 422);
            }
            if ($d === 0.0 && $c === 0.0) {
                return response()->json([
                    'message' => "Baris " . ($i + 1) . ": isi salah satu debit atau kredit (> 0).",
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

        $entry = DB::transaction(function () use ($data, $request, $totalDebit, $totalCredit, $viharaId) {
            $entry = JournalEntry::create([
                'entity_id' => $data['entity_id'],
                'vihara_id' => $viharaId,
                'entry_date' => $data['entry_date'],
                'reference_type' => 'manual',
                'reference_id' => null,
                'description' => $data['description'],
                'reference' => $data['reference'] ?? null,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'status' => 'draft',
                'created_by_user_id' => $request->user()->id,
            ]);

            foreach ($data['lines'] as $line) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_code' => $line['account_code'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'description' => $line['description'] ?? null,
                ]);
            }

            return $entry;
        });

        return response()->json($entry->load('lines', 'entity:id,code', 'vihara:id,code,name'), 201);
    }

    /**
     * POST .../{id}/submit — ajukan draft jurnal ke antrean persetujuan.
     *
     * Kelengkapan ditegakkan di sini, bukan saat penyimpanan: draft memang
     * boleh setengah jadi, tapi yang masuk antrean orang lain harus utuh dan
     * seimbang (FV-53 poin 1).
     */
    public function submit(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $this->pastikanManual($journalEntry);
        ViharaScopeService::guardRecord($request, $journalEntry->vihara_id);
        $this->guardPemilikDraft($request, $journalEntry, 'jurnal');

        if (! in_array($journalEntry->status, ['draft', 'rejected'], true)) {
            return response()->json([
                'message' => 'Hanya jurnal berstatus draft atau ditolak yang bisa diajukan.',
            ], 422);
        }
        if ($kurang = $this->kelengkapanKurang($journalEntry)) {
            return response()->json(['message' => $kurang], 422);
        }

        $journalEntry->forceFill([
            'status' => 'submitted',
            'submitted_at' => now(),
            'submitted_by_user_id' => $request->user()->id,
            // Pengajuan ulang membersihkan jejak penolakan lama.
            'rejected_at' => null,
            'rejected_by_user_id' => null,
            'reject_reason' => null,
        ])->save();

        return response()->json($journalEntry->fresh());
    }

    /**
     * POST .../{id}/post — setujui dan posting jurnal.
     *
     * Dari antrean (submitted), atau langsung dari draft bagi pemegang
     * journal.create + journal.approve. Route-nya dijaga journal.approve,
     * jadi pencatat biasa tidak bisa memposting pekerjaannya sendiri.
     */
    public function post(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $this->pastikanManual($journalEntry);
        ViharaScopeService::guardRecord($request, $journalEntry->vihara_id);

        if (! in_array($journalEntry->status, ['draft', 'submitted'], true)) {
            return response()->json([
                'message' => 'Jurnal ini sudah diproses, tidak bisa diposting lagi.',
            ], 422);
        }
        // Posting LANGSUNG dari draft hanya untuk yang juga boleh mencatat;
        // selain itu jurnal harus lewat antrean dulu supaya ada dua pasang
        // mata, sesuai pola maker-checker modul lain.
        if ($journalEntry->status === 'draft' && ! $request->user()->can('journal.create')) {
            return response()->json([
                'message' => 'Jurnal ini belum diajukan. Minta pembuatnya mengajukan dulu.',
            ], 422);
        }
        if ($kurang = $this->kelengkapanKurang($journalEntry)) {
            return response()->json(['message' => $kurang], 422);
        }

        PeriodLockService::guard($journalEntry->entity_id, $journalEntry->entry_date, 'entry_date');
        $journalEntry->runPostAction($request->user());

        return response()->json($journalEntry->fresh()->load('lines'));
    }

    /** POST .../{id}/reject — tolak pengajuan, dengan alasan yang wajib. */
    public function reject(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $this->pastikanManual($journalEntry);
        ViharaScopeService::guardRecord($request, $journalEntry->vihara_id);

        if ($journalEntry->status !== 'submitted') {
            return response()->json([
                'message' => 'Hanya jurnal yang sedang menunggu persetujuan yang bisa ditolak.',
            ], 422);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'reason.required' => 'Alasan penolakan wajib diisi.',
            'reason.min' => 'Alasan penolakan terlalu singkat.',
        ]);

        // Dikembalikan ke pembuatnya, bukan dihapus: pengaju perlu tahu apa
        // yang salah dan bisa memperbaiki lalu mengajukan lagi.
        $journalEntry->forceFill([
            'status' => 'rejected',
            'rejected_at' => now(),
            'rejected_by_user_id' => $request->user()->id,
            'reject_reason' => $data['reason'],
        ])->save();

        return response()->json($journalEntry->fresh());
    }

    /** Jurnal otomatis dari transaksi tidak diurus di sini. */
    private function pastikanManual(JournalEntry $journalEntry): void
    {
        abort_unless($journalEntry->reference_type === 'manual', 404, 'Bukan manual journal.');
    }

    /**
     * Hanya pembuat draft (atau superadmin) yang boleh mengajukannya —
     * aturan yang sama dengan pembayaran/penerimaan (FV-44).
     */
    private function guardPemilikDraft(Request $request, JournalEntry $entry, string $label): void
    {
        $user = $request->user();
        if ($user->hasRole('superadmin')) {
            return;
        }
        if ($entry->created_by_user_id !== null
            && (int) $entry->created_by_user_id === (int) $user->id) {
            return;
        }

        abort(403, "Hanya pembuat draft {$label} ini yang bisa mengajukannya.");
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
        ViharaScopeService::guardRecord($request, $journalEntry->vihara_id);

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
        ViharaScopeService::guardRecord($request, $journalEntry->vihara_id);

        if ($journalEntry->reference_type !== 'manual') {
            abort(404, 'Bukan manual journal.');
        }
        if ($journalEntry->status !== 'draft') {
            return response()->json(['message' => 'Hanya draft yang bisa dihapus.'], 422);
        }
        $journalEntry->lines()->delete();
        $journalEntry->delete();
        return response()->json(['message' => 'deleted']);
    }
}
