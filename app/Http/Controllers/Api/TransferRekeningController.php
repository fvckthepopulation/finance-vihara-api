<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\ViharaBankAccount;
use App\Services\PeriodLockService;
use App\Services\ViharaScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Transfer antar rekening (FV-56).
 *
 * Konsekuensi langsung dari keputusan "seluruh pengeluaran keluar dari 1120
 * Bank Operasional": donasi masuk ke 1130 Bank Donasi, jadi harus ada jalan
 * memindahkannya ke 1120 sebelum bisa dibelanjakan. Tanpa fitur ini, aturan
 * akun bank itu justru membuat aplikasinya tidak bisa dipakai.
 *
 * Yang dicatat hanya PERPINDAHAN KAS, bukan pendapatan atau beban:
 * Dr rekening tujuan / Kr rekening asal. Pembatasan dana TIDAK ikut pindah —
 * itu dilacak di aset neto (3200) dan dilepas saat dananya dipakai sesuai
 * tujuannya (FV-55), bukan dengan menandai rekeningnya.
 */
class TransferRekeningController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_bank_account_id' => ['required', 'integer', 'exists:vihara_bank_accounts,id'],
            'to_bank_account_id' => ['required', 'integer', 'different:from_bank_account_id', 'exists:vihara_bank_accounts,id'],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'vihara_id' => ['sometimes', 'nullable', 'integer', 'exists:viharas,id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], [
            'to_bank_account_id.different' => 'Rekening asal dan tujuan tidak boleh sama.',
        ]);

        $asal = ViharaBankAccount::findOrFail($data['from_bank_account_id']);
        $tujuan = ViharaBankAccount::findOrFail($data['to_bank_account_id']);

        if ((int) $asal->entity_id !== (int) $tujuan->entity_id) {
            return response()->json([
                'message' => 'Transfer hanya bisa antar rekening dalam satu yayasan.',
            ], 422);
        }
        ViharaScopeService::guardEntity($request, $asal->entity_id);

        if (! $asal->coa_account_code || ! $tujuan->coa_account_code) {
            return response()->json([
                'message' => 'Kedua rekening harus punya akun COA. Lengkapi dulu di Master Rekening.',
            ], 422);
        }
        if ($asal->coa_account_code === $tujuan->coa_account_code) {
            return response()->json([
                'message' => 'Kedua rekening memakai akun yang sama, jadi jurnalnya tidak berarti apa-apa.',
            ], 422);
        }

        // Vihara transaksi: boleh kosong (tingkat yayasan). Kalau diisi,
        // harus vihara yang memang boleh memakai kedua rekening itu.
        $viharaId = $data['vihara_id'] ?? null;
        if ($viharaId !== null) {
            ViharaScopeService::guardRecord($request, $viharaId);
            foreach ([$asal, $tujuan] as $rek) {
                if (! $rek->bolehDipakaiVihara((int) $viharaId)) {
                    return response()->json([
                        'message' => 'Rekening ini tidak boleh dipakai vihara yang dipilih.',
                    ], 422);
                }
            }
        }

        PeriodLockService::guard($asal->entity_id, $data['date'], 'date');

        $jurnal = DB::transaction(function () use ($data, $asal, $tujuan, $viharaId, $request) {
            $desc = ($data['description'] ?? null)
                ?: "Transfer antar rekening — {$asal->label()} → {$tujuan->label()}";

            $jurnal = JournalEntry::create([
                'entity_id' => $asal->entity_id,
                'vihara_id' => $viharaId,
                'entry_date' => $data['date'],
                'reference_type' => 'transfer_rekening',
                'reference_id' => null,
                'description' => $desc,
                'total_debit' => $data['amount'],
                'total_credit' => $data['amount'],
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by_user_id' => $request->user()->id,
                'created_by_user_id' => $request->user()->id,
            ]);

            JournalLine::create([
                'journal_entry_id' => $jurnal->id,
                'account_code' => $tujuan->coa_account_code,
                'debit' => $data['amount'],
                'credit' => 0,
                'description' => $desc,
            ]);
            JournalLine::create([
                'journal_entry_id' => $jurnal->id,
                'account_code' => $asal->coa_account_code,
                'debit' => 0,
                'credit' => $data['amount'],
                'description' => $desc,
            ]);

            return $jurnal;
        });

        return response()->json($jurnal->load('lines'), 201);
    }
}
