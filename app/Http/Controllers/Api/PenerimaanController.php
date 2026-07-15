<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Penerimaan;
use App\Services\PeriodLockService;
use App\Services\ViharaScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PenerimaanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Penerimaan::query()
            ->with([
                'entity:id,code,legal_name',
                'vihara:id,code,name',
                'bankAccount:id,bank_name,account_number',
                'sourcePayee:id,code,name',
                'destinationAccount:code,name',
                'creditAccount:code,name',
            ])
            ->orderByDesc('date')
            ->orderByDesc('id');

        if ($viharaId = ViharaScopeService::readFilter($request)) {
            $q->where('vihara_id', $viharaId);
        }
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

        return response()->json($q->paginate(25));
    }

    public function show(Request $request, Penerimaan $penerimaan): JsonResponse
    {
        ViharaScopeService::guardRecord($request, $penerimaan->vihara_id);

        return response()->json(
            $penerimaan->load([
                'entity', 'vihara:id,code,name', 'bankAccount:id,bank_name,account_number', 'sourcePayee',
                'destinationAccount:code,name', 'creditAccount:code,name',
                'journalEntry.lines',
            ]),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'method' => ['required', 'in:transfer,tunai,auto_credit'],
            // Rekening master vihara — kalau dikirim, destination di-derive
            // dari coa_account_code rekening (override input client).
            'bank_account_id' => ['sometimes', 'nullable', 'integer', 'exists:vihara_bank_accounts,id'],
            // Tunai default ke 1110 Kas; transfer/auto_credit wajib pilih
            // rekening (bank_account_id ATAU destination_account_code).
            'destination_account_code' => [
                Rule::requiredIf(fn () => $request->input('method') !== 'tunai' && ! $request->filled('bank_account_id')),
                'nullable', 'exists:accounts,code',
            ],
            'category' => ['required', 'in:' . implode(',', array_keys(Penerimaan::CATEGORIES))],
            'name' => [
                'sometimes', 'nullable', 'string', 'max:255',
                // Nama Penerimaan harus terdaftar di master, sesuai kategorinya.
                Rule::exists('transaction_names', 'name')
                    ->where('type', 'penerimaan')
                    ->where('category', (string) $request->input('category')),
            ],
            'entity_id' => ['sometimes', 'nullable', 'exists:entities,id'],
            'source_payee_id' => ['sometimes', 'nullable', 'exists:payees,id'],
            'source_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'received_by' => ['sometimes', 'nullable', 'string', 'max:255'],
            'credit_account_code' => ['sometimes', 'nullable', 'exists:accounts,code'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        $data['vihara_id'] = ViharaScopeService::creationViharaId($request);

        // Rekening bank master: wajib milik vihara transaksi; akun tujuan JE
        // mengikuti coa_account_code rekening.
        if (! empty($data['bank_account_id'])) {
            $bankAccount = \App\Models\ViharaBankAccount::find($data['bank_account_id']);
            if ((int) $bankAccount->vihara_id !== (int) $data['vihara_id']) {
                return response()->json([
                    'message' => 'Rekening bank bukan milik vihara transaksi ini.',
                    'errors' => ['bank_account_id' => ['Rekening bank bukan milik vihara transaksi ini.']],
                ], 422);
            }
            $data['destination_account_code'] = $bankAccount->coa_account_code;
        }

        // Single-entity setup (YDI) — default dari user, fallback entity pertama.
        if (empty($data['entity_id'])) {
            $data['entity_id'] = $request->user()->entity_id
                ?? \App\Models\Entity::query()->value('id');
        }
        if (empty($data['destination_account_code'])) {
            $data['destination_account_code'] = '1110'; // Kas — hanya tercapai saat method tunai
        }

        PeriodLockService::guard($data['entity_id'], $data['date'], 'date');

        if (empty($data['credit_account_code'])) {
            $data['credit_account_code'] = Penerimaan::CATEGORIES[$data['category']]['default_credit'];
        }
        if ($data['destination_account_code'] === $data['credit_account_code']) {
            return response()->json([
                'message' => 'Destination account dan credit account tidak boleh sama.',
            ], 422);
        }

        $penerimaan = Penerimaan::create($data + [
            'code' => $this->nextCode(),
            'status' => 'draft',
            'created_by_user_id' => $request->user()->id,
        ]);

        return response()->json(
            $penerimaan->load(['entity', 'vihara:id,code,name', 'bankAccount:id,bank_name,account_number', 'sourcePayee', 'destinationAccount:code,name', 'creditAccount:code,name']),
            201,
        );
    }

    public function post(Request $request, Penerimaan $penerimaan): JsonResponse
    {
        if ($penerimaan->status !== 'draft') {
            return response()->json(['message' => 'Penerimaan tidak dalam status draft.'], 422);
        }

        DB::transaction(function () use ($penerimaan, $request) {
            $desc = "Penerimaan {$penerimaan->code} — " . (Penerimaan::CATEGORIES[$penerimaan->category]['label'] ?? $penerimaan->category);

            $journal = JournalEntry::create([
                'entity_id' => $penerimaan->entity_id,
                'vihara_id' => $penerimaan->vihara_id,
                'entry_date' => $penerimaan->date,
                'reference_type' => 'penerimaan',
                'reference_id' => $penerimaan->id,
                'description' => $desc,
                'total_debit' => $penerimaan->amount,
                'total_credit' => $penerimaan->amount,
                'posted_at' => now(),
                'posted_by_user_id' => $request->user()->id,
            ]);

            // Dr destination (bank/kas naik), Cr credit (revenue naik)
            JournalLine::create([
                'journal_entry_id' => $journal->id,
                'account_code' => $penerimaan->destination_account_code,
                'debit' => $penerimaan->amount,
                'credit' => 0,
                'description' => $penerimaan->description ?? $desc,
            ]);
            JournalLine::create([
                'journal_entry_id' => $journal->id,
                'account_code' => $penerimaan->credit_account_code,
                'debit' => 0,
                'credit' => $penerimaan->amount,
                'description' => $penerimaan->description ?? $desc,
            ]);

            $penerimaan->update([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by_user_id' => $request->user()->id,
                'journal_entry_id' => $journal->id,
            ]);
        });

        return response()->json($penerimaan->fresh()->load('journalEntry.lines'));
    }

    /**
     * Reklasifikasi donasi suspense (2310) yang sudah teridentifikasi →
     * salah satu kategori dana. Membuat JE koreksi Dr 2310 / Kr 4xxx
     * (reference_type 'penerimaan_reklas') dan meng-update row penerimaan.
     * Gate: reconcile.match — aksi ini bagian alur rekonsiliasi/suspense,
     * dilakukan approver, bukan input harian bendahara.
     */
    public function reklasifikasi(Request $request, Penerimaan $penerimaan): JsonResponse
    {
        ViharaScopeService::guardRecord($request, $penerimaan->vihara_id);

        $targets = ['dana_operasional', 'dana_paramita', 'dana_bhikkhu', 'dana_perayaan'];
        $data = $request->validate([
            'category' => ['required', 'in:' . implode(',', $targets)],
            'name' => [
                'sometimes', 'nullable', 'string', 'max:255',
                Rule::exists('transaction_names', 'name')
                    ->where('type', 'penerimaan')
                    ->where('category', (string) $request->input('category')),
            ],
            'source_name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($penerimaan->status !== 'posted') {
            return response()->json(['message' => 'Hanya penerimaan posted yang bisa direklasifikasi.'], 422);
        }
        if ($penerimaan->category !== 'donasi_belum_teridentifikasi') {
            return response()->json(['message' => 'Penerimaan ini sudah direklasifikasi (bukan lagi suspense 2310).'], 422);
        }

        // JE reklas dibukukan hari ini (periode saat identifikasi), bukan
        // tanggal donasi asli — pendapatan diakui saat teridentifikasi.
        $entryDate = now()->toDateString();
        PeriodLockService::guard($penerimaan->entity_id, $entryDate, 'date');

        $creditCode = Penerimaan::CATEGORIES[$data['category']]['default_credit'];
        $label = Penerimaan::CATEGORIES[$data['category']]['label'];

        $journal = DB::transaction(function () use ($penerimaan, $request, $data, $entryDate, $creditCode, $label) {
            $desc = "Reklasifikasi {$penerimaan->code} — suspense 2310 → {$label}";

            $journal = JournalEntry::create([
                'entity_id' => $penerimaan->entity_id,
                'vihara_id' => $penerimaan->vihara_id,
                'entry_date' => $entryDate,
                'reference_type' => 'penerimaan_reklas',
                'reference_id' => $penerimaan->id,
                'description' => $desc,
                'total_debit' => $penerimaan->amount,
                'total_credit' => $penerimaan->amount,
                'posted_at' => now(),
                'posted_by_user_id' => $request->user()->id,
            ]);
            JournalLine::create([
                'journal_entry_id' => $journal->id,
                'account_code' => '2310',
                'debit' => $penerimaan->amount,
                'credit' => 0,
                'description' => $desc,
            ]);
            JournalLine::create([
                'journal_entry_id' => $journal->id,
                'account_code' => $creditCode,
                'debit' => 0,
                'credit' => $penerimaan->amount,
                'description' => $desc,
            ]);

            $note = 'direklasifikasi dari 2310';
            $description = trim((string) ($data['description'] ?? $penerimaan->description ?? ''));
            if (! str_contains($description, $note)) {
                $description = trim($description === '' ? $note : "{$description} ({$note})");
            }

            $penerimaan->update([
                'category' => $data['category'],
                'name' => $data['name'] ?? $penerimaan->name,
                'source_name' => $data['source_name'],
                'description' => $description,
                'credit_account_code' => $creditCode,
            ]);

            return $journal;
        });

        return response()->json([
            'penerimaan' => $penerimaan->fresh()->load([
                'entity', 'vihara:id,code,name', 'bankAccount:id,bank_name,account_number',
                'destinationAccount:code,name', 'creditAccount:code,name',
            ]),
            'reklas_journal_entry_id' => $journal->id,
        ]);
    }

    public function destroy(Penerimaan $penerimaan): JsonResponse
    {
        if ($penerimaan->status !== 'draft') {
            return response()->json(['message' => 'Hanya penerimaan draft yang bisa dihapus.'], 422);
        }
        if ($penerimaan->attachment_path) {
            Storage::disk('local')->delete($penerimaan->attachment_path);
        }
        $penerimaan->delete();

        return response()->json(['message' => 'deleted']);
    }

    public function uploadAttachment(Request $request, Penerimaan $penerimaan): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);
        if ($penerimaan->attachment_path) {
            Storage::disk('local')->delete($penerimaan->attachment_path);
        }
        $path = $request->file('file')->store("penerimaan/{$penerimaan->id}", 'local');
        $penerimaan->update(['attachment_path' => $path]);

        return response()->json($penerimaan->fresh(), 201);
    }

    public function downloadAttachment(Penerimaan $penerimaan): StreamedResponse
    {
        if (! $penerimaan->attachment_path || ! Storage::disk('local')->exists($penerimaan->attachment_path)) {
            abort(404, 'Tidak ada lampiran untuk penerimaan ini.');
        }
        return Storage::disk('local')->response($penerimaan->attachment_path);
    }

    public function categories(): JsonResponse
    {
        $rows = [];
        foreach (Penerimaan::CATEGORIES as $key => $meta) {
            $rows[] = ['key' => $key, 'label' => $meta['label'], 'default_credit' => $meta['default_credit']];
        }
        return response()->json($rows);
    }

    private function nextCode(): string
    {
        $prefix = 'RCV-' . now()->format('ym');
        $last = Penerimaan::query()->where('code', 'like', "$prefix%")->orderByDesc('id')->value('code');
        $n = $last ? ((int) substr($last, -3)) + 1 : 1;
        return $prefix . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }
}
