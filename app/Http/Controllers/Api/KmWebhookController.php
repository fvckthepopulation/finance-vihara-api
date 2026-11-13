<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\KmDonation;
use App\Models\KmSettlement;
use App\Models\Pembayaran;
use App\Models\Penerimaan;
use App\Models\TransactionName;
use App\Models\Vihara;
use App\Models\ViharaBankAccount;
use App\Services\PeriodLockService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Webhook receiver KM Finance (KalyanaMitta) — Phase 7.
 *
 * Dua event M2M (auth: Bearer KM_WEBHOOK_TOKEN, lihat VerifyKmWebhookToken):
 *
 * 1. POST /api/webhooks/km/donations/paid — donasi dibayar di KM.
 *    Membuat km_donations + penerimaan posted (method auto_credit,
 *    Dr 1160 PG Clearing / Kr 4110 (TP) atau 4120 (DP) — donasi via KM).
 * 2. POST /api/webhooks/km/settlements — payout PG ke rekening vihara.
 *    Membuat km_settlements + pembayaran komisi posted (Dr 7140 / Kr 1160)
 *    + JE transfer net (Dr 1130 bank / Kr 1160), lalu menandai donasi settled.
 *
 * Keduanya idempotent (unique km_donation_id / ref) — retry aman.
 * Dokumen kontrak: docs/km-webhook-spec.md.
 */
class KmWebhookController extends Controller
{
    /** Kategori dana yang KM boleh kirim — suspense 2310 tidak diizinkan. */
    private const ALLOWED_CATEGORIES = [
        'dana_operasional', 'dana_paramita', 'dana_bhikkhu', 'dana_perayaan',
    ];

    public function donationPaid(Request $request): JsonResponse
    {
        $data = $request->validate([
            'donation' => ['required', 'array'],
            'donation.km_donation_id' => ['required', 'integer', 'min:1'],
            'donation.ref' => ['required', 'string', 'max:100'],
            'donation.vihara_code' => ['required', 'string', Rule::exists('viharas', 'code')],
            'donation.category' => ['required', 'string', Rule::in(self::ALLOWED_CATEGORIES)],
            'donation.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'donation.donor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'donation.amount' => ['required', 'numeric', 'gt:0'],
            'donation.paid_at' => ['required', 'date'],
        ])['donation'];

        // Idempotensi: km_donation_id / ref sudah pernah diproses → 200.
        $existing = KmDonation::query()
            ->where('km_donation_id', $data['km_donation_id'])
            ->orWhere('ref', $data['ref'])
            ->first();
        if ($existing) {
            return response()->json([
                'already_processed' => true,
                'km_donation_id' => (int) $existing->km_donation_id,
                'penerimaan_id' => $existing->penerimaan_id,
            ]);
        }

        $vihara = Vihara::where('code', $data['vihara_code'])->firstOrFail();
        $entityId = (int) Entity::query()->value('id');

        // Nama transaksi divalidasi terhadap master transaction_names untuk
        // kategori tsb. Tidak match → donasi tetap diterima; nama raw
        // disimpan di km_donations, penerimaan.name dikosongkan.
        $rawName = isset($data['name']) && $data['name'] !== '' ? $data['name'] : null;
        $nameMatched = $rawName !== null && TransactionName::query()
            ->where('type', 'penerimaan')
            ->where('category', $data['category'])
            ->where('name', $rawName)
            ->where('active', true)
            ->exists();

        // Tanggal buku = tanggal paid_at dalam WIB (app timezone Asia/Jakarta).
        $paidAt = Carbon::parse($data['paid_at']);
        $bookDate = $paidAt->copy()->setTimezone(config('app.timezone'))->toDateString();

        PeriodLockService::guard($entityId, $bookDate, 'donation.paid_at');

        $donation = DB::transaction(function () use ($data, $vihara, $entityId, $rawName, $nameMatched, $paidAt, $bookDate) {
            $creditCode = Penerimaan::creditCodeFor($data['category'], 'auto_credit');
            $donorLabel = $data['donor_name'] ?? 'Anonim';
            $nameLabel = $rawName
                ?? (Penerimaan::CATEGORIES[$data['category']]['label'] ?? $data['category']);
            $description = "KalyanaMitta | {$nameLabel} | {$donorLabel} | Paid";

            // Penerimaan auto_credit ke 1160 PG Clearing — alur yang sama
            // dengan input manual bendahara, tapi created/posted by system
            // (created_by_user_id & posted_by_user_id null).
            $penerimaan = Penerimaan::create([
                'code' => $this->nextPenerimaanCode(),
                'date' => $bookDate,
                'method' => 'auto_credit',
                'destination_account_code' => '1160',
                'category' => $data['category'],
                'name' => $nameMatched ? $rawName : null,
                'entity_id' => $entityId,
                'vihara_id' => $vihara->id,
                'source_name' => $data['donor_name'] ?? null,
                'credit_account_code' => $creditCode,
                'reference' => $data['ref'],
                'amount' => $data['amount'],
                'description' => $description,
                'status' => 'draft',
                'created_by_user_id' => null,
            ]);

            $journal = $this->postJournal(
                entityId: $entityId,
                viharaId: $vihara->id,
                entryDate: $bookDate,
                referenceType: 'penerimaan',
                referenceId: $penerimaan->id,
                description: "Penerimaan {$penerimaan->code} — {$description}",
                debitCode: '1160',
                creditCode: $creditCode,
                amount: (float) $data['amount'],
            );

            $penerimaan->update([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by_user_id' => null,
                'journal_entry_id' => $journal->id,
            ]);

            return KmDonation::create([
                'km_donation_id' => $data['km_donation_id'],
                'ref' => $data['ref'],
                'vihara_id' => $vihara->id,
                'category' => $data['category'],
                'name' => $rawName,
                'donor_name' => $data['donor_name'] ?? null,
                'amount' => $data['amount'],
                'status' => 'paid',
                'paid_at' => $paidAt,
                'penerimaan_id' => $penerimaan->id,
            ]);
        });

        return response()->json([
            'km_donation_id' => (int) $donation->km_donation_id,
            'penerimaan_id' => $donation->penerimaan_id,
            'journal_entry_id' => $donation->penerimaan->journal_entry_id,
            'name_matched' => $nameMatched,
        ], 201);
    }

    public function settlement(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settlement' => ['required', 'array'],
            'settlement.ref' => ['required', 'string', 'max:100'],
            'settlement.vihara_code' => ['required', 'string', Rule::exists('viharas', 'code')],
            'settlement.bank_account_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'settlement.gross' => ['required', 'numeric', 'gt:0'],
            'settlement.komisi' => ['required', 'numeric', 'gte:0'],
            'settlement.net' => ['required', 'numeric', 'gte:0'],
            'settlement.settled_at' => ['required', 'date'],
            'settlement.km_donation_ids' => ['required', 'array', 'min:1'],
            'settlement.km_donation_ids.*' => ['integer', 'min:1'],
        ])['settlement'];

        // Idempotensi by ref.
        $existing = KmSettlement::where('ref', $data['ref'])->first();
        if ($existing) {
            return response()->json([
                'already_processed' => true,
                'settlement_id' => $existing->id,
                'journal_entry_id' => $existing->journal_entry_id,
                'komisi_pembayaran_id' => $existing->komisi_pembayaran_id,
            ]);
        }

        $vihara = Vihara::where('code', $data['vihara_code'])->firstOrFail();
        $entityId = (int) Entity::query()->value('id');
        $ids = array_values(array_unique(array_map('intval', $data['km_donation_ids'])));

        $donations = KmDonation::query()->whereIn('km_donation_id', $ids)->get();

        // Semua donasi harus dikenal, milik vihara yang sama, dan masih paid.
        $missing = array_values(array_diff($ids, $donations->pluck('km_donation_id')->map(fn ($v) => (int) $v)->all()));
        if ($missing !== []) {
            return $this->unprocessable('settlement.km_donation_ids', 'Donasi KM tidak dikenal: ' . implode(', ', $missing) . '.');
        }
        $foreign = $donations->where('vihara_id', '!=', $vihara->id)->pluck('km_donation_id');
        if ($foreign->isNotEmpty()) {
            return $this->unprocessable('settlement.km_donation_ids', 'Donasi bukan milik vihara ' . $vihara->code . ': ' . $foreign->implode(', ') . '.');
        }
        $notPaid = $donations->where('status', '!=', 'paid')->pluck('km_donation_id');
        if ($notPaid->isNotEmpty()) {
            return $this->unprocessable('settlement.km_donation_ids', 'Donasi sudah settled sebelumnya: ' . $notPaid->implode(', ') . '.');
        }

        // Konsistensi angka: gross = Σ amount donasi; net = gross − komisi.
        $sum = round((float) $donations->sum(fn ($d) => (float) $d->amount), 2);
        if (round((float) $data['gross'], 2) !== $sum) {
            return $this->unprocessable('settlement.gross', "Gross tidak sama dengan total donasi ({$sum}).");
        }
        if (round((float) $data['net'], 2) !== round((float) $data['gross'] - (float) $data['komisi'], 2)) {
            return $this->unprocessable('settlement.net', 'Net harus sama dengan gross dikurangi komisi.');
        }

        // Rekening tujuan payout: cocokkan digit-only ke master rekening
        // vihara; tidak ketemu → fallback rekening aktif pertama.
        $bank = $this->matchBankAccount($vihara, $data['bank_account_number'] ?? null);
        $bankMatched = $bank['matched'];
        $bankAccount = $bank['account'];

        $settledAt = Carbon::parse($data['settled_at']);
        $bookDate = $settledAt->copy()->setTimezone(config('app.timezone'))->toDateString();
        PeriodLockService::guard($entityId, $bookDate, 'settlement.settled_at');

        $komisi = round((float) $data['komisi'], 2);
        $net = round((float) $data['net'], 2);

        $settlement = DB::transaction(function () use ($data, $vihara, $entityId, $donations, $bankAccount, $settledAt, $bookDate, $komisi, $net) {
            $settlement = KmSettlement::create([
                'ref' => $data['ref'],
                'vihara_id' => $vihara->id,
                'bank_account_id' => $bankAccount?->id,
                'gross' => $data['gross'],
                'komisi' => $komisi,
                'net' => $net,
                'settled_at' => $settledAt,
            ]);

            // 1. Komisi platform → pembayaran posted Dr 7140 / Kr 1160.
            $pembayaranId = null;
            if ($komisi > 0) {
                $desc = "Komisi KM settlement {$data['ref']} — {$donations->count()} donasi";
                $pembayaran = Pembayaran::create([
                    'code' => $this->nextPembayaranCode(),
                    'date' => $bookDate,
                    'method' => 'auto_debit',
                    'source_account_code' => '1160',
                    'category' => 'komisi_platform',
                    'entity_id' => $entityId,
                    'vihara_id' => $vihara->id,
                    'payee_name' => 'KalyanaMitta',
                    'debit_account_code' => Pembayaran::CATEGORIES['komisi_platform']['default_debit'],
                    'reference' => $data['ref'],
                    'amount' => $komisi,
                    'description' => $desc,
                    'status' => 'draft',
                    'created_by_user_id' => null,
                ]);
                $journal = $this->postJournal(
                    entityId: $entityId,
                    viharaId: $vihara->id,
                    entryDate: $bookDate,
                    referenceType: 'pembayaran',
                    referenceId: $pembayaran->id,
                    description: "Pembayaran {$pembayaran->code} — {$desc}",
                    debitCode: $pembayaran->debit_account_code,
                    creditCode: '1160',
                    amount: $komisi,
                );
                $pembayaran->update([
                    'status' => 'posted',
                    'posted_at' => now(),
                    'posted_by_user_id' => null,
                    'journal_entry_id' => $journal->id,
                ]);
                $pembayaranId = $pembayaran->id;
            }

            // 2. Transfer net PG → bank vihara: JE Dr 1130 (coa rekening) / Kr 1160.
            $netJournalId = null;
            if ($net > 0) {
                $bankLabel = $bankAccount?->label() ?? 'Bank Donasi';
                $netJournal = $this->postJournal(
                    entityId: $entityId,
                    viharaId: $vihara->id,
                    entryDate: $bookDate,
                    referenceType: 'km_settlement',
                    referenceId: $settlement->id,
                    description: "Settlement KM {$data['ref']} → {$bankLabel}",
                    debitCode: $bankAccount?->coa_account_code ?? '1130',
                    creditCode: '1160',
                    amount: $net,
                );
                $netJournalId = $netJournal->id;
            }

            $settlement->update([
                'journal_entry_id' => $netJournalId,
                'komisi_pembayaran_id' => $pembayaranId,
            ]);

            // 3. Tandai donasi settled.
            KmDonation::whereIn('id', $donations->pluck('id'))->update([
                'status' => 'settled',
                'settled_at' => $settledAt,
                'settlement_id' => $settlement->id,
                'updated_at' => now(),
            ]);

            return $settlement;
        });

        return response()->json([
            'settlement_id' => $settlement->id,
            'journal_entry_id' => $settlement->journal_entry_id,
            'komisi_pembayaran_id' => $settlement->komisi_pembayaran_id,
            'donations_settled' => $donations->count(),
            'bank_matched' => $bankMatched,
        ], 201);
    }

    // ------------------------------------------------------------------

    /**
     * Cocokkan nomor rekening payload ke master vihara_bank_accounts
     * (perbandingan digit-only, "6280-3488-88" == "628034888 8").
     * Tidak ketemu / tidak dikirim → fallback rekening aktif pertama.
     *
     * @return array{matched: bool, account: ?ViharaBankAccount}
     */
    private function matchBankAccount(Vihara $vihara, ?string $number): array
    {
        $accounts = ViharaBankAccount::query()
            ->where('vihara_id', $vihara->id)
            ->orderByDesc('active')->orderBy('id')
            ->get();

        $digits = $number !== null ? preg_replace('/\D+/', '', $number) : '';
        if ($digits !== '') {
            foreach ($accounts as $account) {
                if (preg_replace('/\D+/', '', (string) $account->account_number) === $digits) {
                    return ['matched' => true, 'account' => $account];
                }
            }
        }

        return ['matched' => false, 'account' => $accounts->firstWhere('active', true)];
    }

    /** JE posted 2 baris (Dr/Kr) — dibuat sistem, posted_by_user_id null. */
    private function postJournal(
        int $entityId,
        int $viharaId,
        string $entryDate,
        string $referenceType,
        int $referenceId,
        string $description,
        string $debitCode,
        string $creditCode,
        float $amount,
    ): JournalEntry {
        $journal = JournalEntry::create([
            'entity_id' => $entityId,
            'vihara_id' => $viharaId,
            'entry_date' => $entryDate,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'description' => $description,
            'total_debit' => $amount,
            'total_credit' => $amount,
            'posted_at' => now(),
            'posted_by_user_id' => null,
        ]);
        JournalLine::create([
            'journal_entry_id' => $journal->id,
            'account_code' => $debitCode,
            'debit' => $amount,
            'credit' => 0,
            'description' => $description,
        ]);
        JournalLine::create([
            'journal_entry_id' => $journal->id,
            'account_code' => $creditCode,
            'debit' => 0,
            'credit' => $amount,
            'description' => $description,
        ]);

        return $journal;
    }

    private function unprocessable(string $field, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => [$field => [$message]],
        ], 422);
    }

    /** Sama dengan PenerimaanController::nextCode — RCV-YYMMNNN. */
    private function nextPenerimaanCode(): string
    {
        $prefix = 'RCV-' . now()->format('ym');
        $last = Penerimaan::query()->where('code', 'like', "$prefix%")->orderByDesc('id')->value('code');
        $n = $last ? ((int) substr($last, -3)) + 1 : 1;

        return $prefix . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    /** Sama dengan PembayaranController::nextCode — PAY-YYMMNNN. */
    private function nextPembayaranCode(): string
    {
        $prefix = 'PAY-' . now()->format('ym');
        $last = Pembayaran::query()->where('code', 'like', "$prefix%")->orderByDesc('id')->value('code');
        $n = $last ? ((int) substr($last, -3)) + 1 : 1;

        return $prefix . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }
}
