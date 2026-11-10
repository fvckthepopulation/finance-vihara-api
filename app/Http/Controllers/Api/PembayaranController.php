<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Pembayaran;
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

        return response()->json($q->paginate(25));
    }

    public function show(Request $request, Pembayaran $pembayaran): JsonResponse
    {
        ViharaScopeService::guardRecord($request, $pembayaran->vihara_id);

        return response()->json(
            $pembayaran->load([
                'entity', 'vihara:id,code,name', 'bankAccount:id,bank_name,account_number', 'payee',
                'sourceAccount:code,name', 'debitAccount:code,name',
                'journalEntry.lines',
            ]),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'method' => ['required', 'in:transfer,tunai,auto_debit'],
            // Rekening master vihara — kalau dikirim, source di-derive dari
            // coa_account_code rekening (override input client).
            'bank_account_id' => ['sometimes', 'nullable', 'integer', 'exists:vihara_bank_accounts,id'],
            // Tunai default ke 1115 Kas Penerimaan Langsung; transfer/
            // auto_debit wajib pilih rekening (bank_account_id ATAU
            // source_account_code).
            'source_account_code' => [
                Rule::requiredIf(fn () => $request->input('method') !== 'tunai' && ! $request->filled('bank_account_id')),
                'nullable', 'exists:accounts,code',
            ],
            'category' => ['required', 'in:' . implode(',', array_keys(Pembayaran::CATEGORIES))],
            'name' => [
                'sometimes', 'nullable', 'string', 'max:255',
                // Nama Pengeluaran harus terdaftar di master, sesuai kategorinya.
                Rule::exists('transaction_names', 'name')
                    ->where('type', 'pengeluaran')
                    ->where('category', (string) $request->input('category')),
            ],
            'entity_id' => ['sometimes', 'nullable', 'exists:entities,id'],
            'payee_id' => ['sometimes', 'nullable', 'exists:payees,id'],
            'payee_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'approved_by_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'debit_account_code' => ['sometimes', 'nullable', 'exists:accounts,code'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        $data['vihara_id'] = ViharaScopeService::creationViharaId($request);

        // Rekening bank master: wajib milik vihara transaksi; akun sumber JE
        // mengikuti coa_account_code rekening.
        if (! empty($data['bank_account_id'])) {
            $bankAccount = \App\Models\ViharaBankAccount::find($data['bank_account_id']);
            if ((int) $bankAccount->vihara_id !== (int) $data['vihara_id']) {
                return response()->json([
                    'message' => 'Rekening bank bukan milik vihara transaksi ini.',
                    'errors' => ['bank_account_id' => ['Rekening bank bukan milik vihara transaksi ini.']],
                ], 422);
            }
            $data['source_account_code'] = $bankAccount->coa_account_code;
        }

        // Single-entity setup (YDI) — default dari user, fallback entity pertama.
        if (empty($data['entity_id'])) {
            $data['entity_id'] = $request->user()->entity_id
                ?? \App\Models\Entity::query()->value('id');
        }
        if (empty($data['source_account_code'])) {
            $data['source_account_code'] = '1115'; // Kas Penerimaan Langsung — hanya tercapai saat method tunai
        }

        PeriodLockService::guard($data['entity_id'], $data['date'], 'date');

        if (empty($data['debit_account_code'])) {
            $data['debit_account_code'] = Pembayaran::CATEGORIES[$data['category']]['default_debit'];
        }
        if ($data['source_account_code'] === $data['debit_account_code']) {
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
        if ($pembayaran->status !== 'draft') {
            return response()->json(['message' => 'Pembayaran tidak dalam status draft.'], 422);
        }

        DB::transaction(function () use ($pembayaran, $request) {
            $desc = "Pembayaran {$pembayaran->code} — " . (Pembayaran::CATEGORIES[$pembayaran->category]['label'] ?? $pembayaran->category);

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

    public function destroy(Pembayaran $pembayaran): JsonResponse
    {
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

    public function downloadAttachment(Pembayaran $pembayaran): StreamedResponse
    {
        if (! $pembayaran->attachment_path || ! Storage::disk('local')->exists($pembayaran->attachment_path)) {
            abort(404, 'Tidak ada lampiran untuk pembayaran ini.');
        }
        return Storage::disk('local')->response($pembayaran->attachment_path);
    }

    public function categories(): JsonResponse
    {
        $rows = [];
        foreach (Pembayaran::CATEGORIES as $key => $meta) {
            $rows[] = ['key' => $key, 'label' => $meta['label'], 'default_debit' => $meta['default_debit']];
        }
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
