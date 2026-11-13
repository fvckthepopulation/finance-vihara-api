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
        return response()->json(
            $journalEntry->load(['entity:id,code,legal_name', 'vihara:id,code,name', 'lines', 'lines.account:code,name', 'createdBy:id,name', 'postedBy:id,name']),
        );
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
