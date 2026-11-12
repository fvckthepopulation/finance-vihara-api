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
            $q->where('description', 'like', "%{$search}%");
        }

        $perPage = max(1, min(100, (int) $request->integer('per_page', 25)));
        return response()->json($q->paginate($perPage));
    }

    public function show(JournalEntry $journalEntry): JsonResponse
    {
        // Viewable for any source (manual + auto-posted). Deletion stays
        // manual-only (see destroy()).
        return response()->json(
            $journalEntry->load(['entity:id,code,legal_name', 'vihara:id,code,name', 'lines', 'lines.account:code,name', 'createdBy:id,name', 'postedBy:id,name']),
        );
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
