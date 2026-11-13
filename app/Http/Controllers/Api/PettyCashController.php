<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VoidService;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PettyCashMovement;
use App\Models\PettyCashPic;
use App\Models\ViharaBankAccount;
use App\Services\PeriodLockService;
use App\Services\ViharaScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        // Pencarian (FV-27): keterangan, no. bukti, dan nama PIC.
        if ($cari = $request->string('q')->toString()) {
            $like = '%' . mb_strtolower($cari) . '%';
            $query->where(function ($w) use ($like) {
                $w->whereRaw('LOWER(description) LIKE ?', [$like])
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

        // Jumlah baris mengikuti pilihan pengguna (FV-26), dibatasi 1..100.
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);
        $paginator = $query->paginate($perPage)->through(function (PettyCashMovement $m) {
            return $m->toArray() + [
                'pic_name' => $m->pic?->name,
                'source_bank_label' => $m->sourceBankAccount?->label(),
            ];
        });

        return response()->json($paginator);
    }

    /**
     * Ringkasan Kas Kecil per PIC: saldo awal (topup posted pertama),
     * total isi/pakai, dan saldo berjalan. Hanya movement posted.
     */
    public function summary(Request $request): JsonResponse
    {
        $viharaIds = ViharaScopeService::readFilterIds($request);

        $pics = PettyCashPic::query()
            ->where('active', true)
            ->when($viharaIds !== null, fn ($q) => $q->whereIn('vihara_id', $viharaIds))
            ->orderBy('name')
            ->get(['id', 'vihara_id', 'name']);

        $agg = PettyCashMovement::query()
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

        // Saldo awal = amount topup posted pertama per PIC (0 kalau belum ada).
        $firstTopups = PettyCashMovement::query()
            ->where('status', 'posted')
            ->where('subtype', 'topup')
            ->whereNotNull('pic_id')
            ->orderBy('movement_date')
            ->orderBy('id')
            ->get(['pic_id', 'amount'])
            ->unique('pic_id')
            ->keyBy('pic_id');

        $total = 0.0;
        $rows = $pics->map(function (PettyCashPic $pic) use ($agg, $firstTopups, &$total) {
            $a = $agg->get($pic->id);
            $isi = (float) ($a->total_isi ?? 0);
            $pakai = (float) ($a->total_pakai ?? 0);
            $saldo = $isi - $pakai;
            $total += $saldo;

            return [
                'id' => $pic->id,
                'name' => $pic->name,
                'saldo_awal' => number_format((float) ($firstTopups->get($pic->id)?->amount ?? 0), 2, '.', ''),
                'saldo' => number_format($saldo, 2, '.', ''),
                'total_isi' => number_format($isi, 2, '.', ''),
                'total_pakai' => number_format($pakai, 2, '.', ''),
                'count' => (int) ($a->cnt ?? 0),
            ];
        })->values();

        return response()->json([
            'pics' => $rows,
            'total' => number_format($total, 2, '.', ''),
        ]);
    }

    public function categories(): JsonResponse
    {
        $rows = [];
        foreach (PettyCashMovement::CATEGORIES as $key => $meta) {
            $rows[] = ['key' => $key, 'label' => $meta['label'], 'default_counterpart' => $meta['default_counterpart']];
        }
        return response()->json($rows);
    }

    /**
     * Kas Kecil per PIC: topup (isi dari bank/kas tunai) atau usage
     * (pemakaian). Akun kas kecil selalu 1110; counterpart di-derive:
     * topup → coa rekening sumber (atau 1115 kas penerimaan), usage → 6100.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'pic_id' => ['required', 'integer', 'exists:petty_cash_pics,id'],
            'subtype' => ['required', 'in:topup,usage'],
            'amount' => ['required', 'numeric', 'gt:0'],
            // Sumber dana topup; null = dari kas penerimaan langsung (1115).
            // Tidak berlaku untuk usage.
            'source_bank_account_id' => [
                'sometimes', 'nullable', 'integer', 'exists:vihara_bank_accounts,id',
                'prohibited_if:subtype,usage',
            ],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string'],
            'entity_id' => ['sometimes', 'nullable', 'exists:entities,id'],
        ]);

        $viharaId = ViharaScopeService::creationViharaId($request);

        $pic = PettyCashPic::find($data['pic_id']);
        if ((int) $pic->vihara_id !== (int) $viharaId) {
            return response()->json([
                'message' => 'PIC kas kecil bukan milik vihara transaksi ini.',
                'errors' => ['pic_id' => ['PIC kas kecil bukan milik vihara transaksi ini.']],
            ], 422);
        }

        $counterpart = $data['subtype'] === 'usage' ? '6100' : '1115';
        if (! empty($data['source_bank_account_id'])) {
            $bankAccount = ViharaBankAccount::find($data['source_bank_account_id']);
            if ((int) $bankAccount->vihara_id !== (int) $viharaId) {
                return response()->json([
                    'message' => 'Rekening bank bukan milik vihara transaksi ini.',
                    'errors' => ['source_bank_account_id' => ['Rekening bank bukan milik vihara transaksi ini.']],
                ], 422);
            }
            $counterpart = $bankAccount->coa_account_code;
        }

        $entityId = $data['entity_id']
            ?? $request->user()->entity_id
            ?? \App\Models\Entity::query()->value('id');

        PeriodLockService::guard($entityId, $data['date'], 'date');

        $movement = PettyCashMovement::create([
            'entity_id' => $entityId,
            'vihara_id' => $viharaId,
            'pic_id' => $pic->id,
            'subtype' => $data['subtype'],
            'source_bank_account_id' => $data['source_bank_account_id'] ?? null,
            'cash_account_code' => '1110',
            'type' => $data['subtype'] === 'topup' ? 'refill' : 'expense',
            'movement_date' => $data['date'],
            'amount' => $data['amount'],
            'counterpart_account_code' => $counterpart,
            'recipient' => $pic->name,
            'reference' => $data['reference'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => 'draft',
            'created_by_user_id' => $request->user()->id,
        ]);

        return response()->json(
            $movement->load([
                'entity:id,code', 'vihara:id,code,name', 'pic:id,name',
                'sourceBankAccount:id,bank_name,account_number',
                'cashAccount:code,name', 'counterpartAccount:code,name',
            ]),
            201,
        );
    }

    public function show(Request $request, PettyCashMovement $pettyCashMovement): JsonResponse
    {
        if ($pettyCashMovement->vihara_id !== null) {
            ViharaScopeService::guardRecord($request, $pettyCashMovement->vihara_id);
        }

        return response()->json(
            $pettyCashMovement->load([
                'entity', 'vihara:id,code,name', 'pic:id,name',
                'sourceBankAccount:id,bank_name,account_number',
                'cashAccount:code,name', 'counterpartAccount:code,name',
                'journalEntry.lines',
            ]),
        );
    }

    public function post(Request $request, PettyCashMovement $pettyCashMovement): JsonResponse
    {
        if ($pettyCashMovement->status !== 'draft') {
            return response()->json(['message' => 'Movement tidak dalam status draft.'], 422);
        }

        DB::transaction(function () use ($pettyCashMovement, $request) {
            $isRefill = $pettyCashMovement->type === 'refill';

            $debitCode = $isRefill ? $pettyCashMovement->cash_account_code : $pettyCashMovement->counterpart_account_code;
            $creditCode = $isRefill ? $pettyCashMovement->counterpart_account_code : $pettyCashMovement->cash_account_code;

            if ($pettyCashMovement->subtype !== null) {
                $picName = $pettyCashMovement->pic?->name ?? $pettyCashMovement->recipient;
                $desc = $isRefill
                    ? "Pengisian Kas Kecil — {$picName}"
                    : "Pemakaian Kas Kecil — {$picName}";
            } else {
                $desc = $isRefill
                    ? "Refill Kas Kecil — {$pettyCashMovement->recipient}"
                    : "Pengeluaran Kas Kecil — " . ($pettyCashMovement->category ?? 'Lainnya');
            }

            $journal = JournalEntry::create([
                'entity_id' => $pettyCashMovement->entity_id,
                'vihara_id' => $pettyCashMovement->vihara_id,
                'entry_date' => $pettyCashMovement->movement_date,
                'reference_type' => 'petty_cash',
                'reference_id' => $pettyCashMovement->id,
                'description' => $desc,
                'total_debit' => $pettyCashMovement->amount,
                'total_credit' => $pettyCashMovement->amount,
                'posted_at' => now(),
                'posted_by_user_id' => $request->user()->id,
            ]);

            JournalLine::create([
                'journal_entry_id' => $journal->id,
                'account_code' => $debitCode,
                'debit' => $pettyCashMovement->amount,
                'credit' => 0,
                'description' => $pettyCashMovement->description ?? $desc,
            ]);
            JournalLine::create([
                'journal_entry_id' => $journal->id,
                'account_code' => $creditCode,
                'debit' => 0,
                'credit' => $pettyCashMovement->amount,
                'description' => $pettyCashMovement->description ?? $desc,
            ]);

            $pettyCashMovement->update([
                'status' => 'posted',
                'posted_at' => now(),
                'journal_entry_id' => $journal->id,
            ]);
        });

        return response()->json($pettyCashMovement->fresh()->load('journalEntry.lines'));
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

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        if ($pettyCashMovement->attachment_path) {
            Storage::disk('local')->delete($pettyCashMovement->attachment_path);
        }

        $path = $request->file('file')->store("petty-cash/{$pettyCashMovement->id}", 'local');
        $pettyCashMovement->update(['attachment_path' => $path]);

        return response()->json(
            $pettyCashMovement->fresh()->load('entity:id,code', 'cashAccount:code,name', 'counterpartAccount:code,name'),
            201,
        );
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
