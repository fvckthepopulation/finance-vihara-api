<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PettyCashPic;
use App\Models\ViharaBankAccount;
use App\Services\ViharaScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Kas & Bank — posisi saldo per rekening bank vihara, kas kecil per PIC,
 * kas tunai, plus mutasi bank terakhir. Semua angka dari dokumen posted.
 */
class KasBankController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'vihara_id' => ['sometimes', 'integer', 'exists:viharas,id'],
            'bank_account_id' => ['sometimes', 'integer', 'exists:vihara_bank_accounts,id'],
        ]);

        $viharaId = ViharaScopeService::readFilter($request);
        $bankAccountId = $request->integer('bank_account_id') ?: null;

        return response()->json([
            'bank_accounts' => $this->bankAccounts($viharaId),
            'kas_kecil' => $this->kasKecil($viharaId),
            'kas_tunai' => $this->kasTunai($viharaId),
            'mutasi' => $this->mutasi($viharaId, $bankAccountId),
        ]);
    }

    /** Saldo per rekening = penerimaan − pembayaran − topup kas kecil (posted) + net settlement KM. */
    private function bankAccounts(?int $viharaId): array
    {
        $accounts = ViharaBankAccount::query()
            ->with('vihara:id,name')
            ->where('active', true)
            ->when($viharaId, fn ($q) => $q->where('vihara_id', $viharaId))
            ->orderBy('bank_name')
            ->orderBy('id')
            ->get();

        $in = DB::table('penerimaan')->where('status', 'posted')->whereNotNull('bank_account_id')
            ->groupBy('bank_account_id')->selectRaw('bank_account_id, SUM(amount) AS s')
            ->pluck('s', 'bank_account_id');
        $out = DB::table('pembayaran')->where('status', 'posted')->whereNotNull('bank_account_id')
            ->groupBy('bank_account_id')->selectRaw('bank_account_id, SUM(amount) AS s')
            ->pluck('s', 'bank_account_id');
        $topup = DB::table('petty_cash_movements')->where('status', 'posted')
            ->where('subtype', 'topup')->whereNotNull('source_bank_account_id')
            ->groupBy('source_bank_account_id')->selectRaw('source_bank_account_id, SUM(amount) AS s')
            ->pluck('s', 'source_bank_account_id');
        // Payout PG Clearing → rekening (JE Dr 1130 / Kr 1160, Phase 7 KM).
        $settleIn = DB::table('km_settlements')->whereNotNull('bank_account_id')
            ->groupBy('bank_account_id')->selectRaw('bank_account_id, SUM(net) AS s')
            ->pluck('s', 'bank_account_id');

        return $accounts->map(function (ViharaBankAccount $a) use ($in, $out, $topup, $settleIn) {
            $saldo = (float) ($in[$a->id] ?? 0) - (float) ($out[$a->id] ?? 0) - (float) ($topup[$a->id] ?? 0)
                + (float) ($settleIn[$a->id] ?? 0);

            return [
                'id' => $a->id,
                'vihara_id' => $a->vihara_id,
                'vihara_name' => $a->vihara?->name,
                'bank_name' => $a->bank_name,
                'account_number' => $a->account_number,
                'account_name' => $a->account_name,
                'saldo' => number_format($saldo, 2, '.', ''),
            ];
        })->values()->all();
    }

    /** Saldo kas kecil per PIC (posted topup − posted usage). */
    private function kasKecil(?int $viharaId): array
    {
        $pics = PettyCashPic::query()
            ->where('active', true)
            ->when($viharaId, fn ($q) => $q->where('vihara_id', $viharaId))
            ->orderBy('name')
            ->get(['id', 'name']);

        $agg = DB::table('petty_cash_movements')
            ->where('status', 'posted')
            ->whereNotNull('pic_id')
            ->groupBy('pic_id')
            ->selectRaw("pic_id, COALESCE(SUM(CASE WHEN subtype = 'topup' THEN amount ELSE -amount END), 0) AS saldo")
            ->pluck('saldo', 'pic_id');

        $total = 0.0;
        $rows = $pics->map(function ($pic) use ($agg, &$total) {
            $saldo = (float) ($agg[$pic->id] ?? 0);
            $total += $saldo;

            return [
                'id' => $pic->id,
                'name' => $pic->name,
                'saldo' => number_format($saldo, 2, '.', ''),
            ];
        })->values()->all();

        return ['pics' => $rows, 'total' => number_format($total, 2, '.', '')];
    }

    /** Kas tunai = penerimaan tunai − pembayaran tunai − topup kas kecil dari kas tunai (posted). */
    private function kasTunai(?int $viharaId): string
    {
        $in = (float) DB::table('penerimaan')->where('status', 'posted')->where('method', 'tunai')
            ->when($viharaId, fn ($q) => $q->where('vihara_id', $viharaId))->sum('amount');
        $out = (float) DB::table('pembayaran')->where('status', 'posted')->where('method', 'tunai')
            ->when($viharaId, fn ($q) => $q->where('vihara_id', $viharaId))->sum('amount');
        $topup = (float) DB::table('petty_cash_movements')->where('status', 'posted')
            ->where('subtype', 'topup')->whereNull('source_bank_account_id')
            ->when($viharaId, fn ($q) => $q->where('vihara_id', $viharaId))->sum('amount');

        return number_format($in - $out - $topup, 2, '.', '');
    }

    /**
     * Mutasi bank terakhir (cap 50): penerimaan/pembayaran posted yang lewat
     * rekening + pengisian kas kecil bersumber rekening.
     */
    private function mutasi(?int $viharaId, ?int $bankAccountId): array
    {
        $scope = function ($q, string $bankColumn) use ($viharaId, $bankAccountId) {
            $q->where('status', 'posted')->whereNotNull($bankColumn);
            if ($viharaId) {
                $q->where('vihara_id', $viharaId);
            }
            if ($bankAccountId) {
                $q->where($bankColumn, $bankAccountId);
            }

            return $q;
        };

        $penerimaan = $scope(DB::table('penerimaan'), 'bank_account_id')->selectRaw(
            "id, date, COALESCE(name, code) AS name, 'Penerimaan' AS tipe, "
            . 'bank_account_id, amount AS masuk, NULL::numeric AS keluar',
        );
        $pembayaran = $scope(DB::table('pembayaran'), 'bank_account_id')->selectRaw(
            "id, date, COALESCE(name, code) AS name, 'Pengeluaran' AS tipe, "
            . 'bank_account_id, NULL::numeric AS masuk, amount AS keluar',
        );
        $topup = $scope(DB::table('petty_cash_movements')->where('subtype', 'topup'), 'source_bank_account_id')
            ->selectRaw(
                "id, movement_date AS date, 'Pengisian Kas Kecil — ' || COALESCE(recipient, '-') AS name, "
                . "'Pengeluaran' AS tipe, source_bank_account_id AS bank_account_id, "
                . 'NULL::numeric AS masuk, amount AS keluar',
            );

        // Payout settlement KM masuk rekening (net) — tidak lewat penerimaan.
        $settlement = DB::table('km_settlements')
            ->whereNotNull('bank_account_id')
            ->when($viharaId, fn ($q) => $q->where('vihara_id', $viharaId))
            ->when($bankAccountId, fn ($q) => $q->where('bank_account_id', $bankAccountId))
            ->selectRaw(
                "id, settled_at::date AS date, 'Settlement KM ' || ref AS name, "
                . "'Penerimaan' AS tipe, bank_account_id, net AS masuk, NULL::numeric AS keluar",
            );

        $rows = DB::query()
            ->fromSub($penerimaan->unionAll($pembayaran)->unionAll($topup)->unionAll($settlement), 'm')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $labels = ViharaBankAccount::query()
            ->get(['id', 'bank_name', 'account_number'])
            ->mapWithKeys(fn ($a) => [$a->id => $a->label()]);

        return $rows->map(fn ($r) => [
            'date' => $r->date instanceof \DateTimeInterface ? $r->date->format('Y-m-d') : substr((string) $r->date, 0, 10),
            'name' => $r->name,
            'tipe' => $r->tipe,
            'bank_account_id' => (int) $r->bank_account_id,
            'bank_label' => $labels[(int) $r->bank_account_id] ?? null,
            'masuk' => $r->masuk !== null ? number_format((float) $r->masuk, 2, '.', '') : null,
            'keluar' => $r->keluar !== null ? number_format((float) $r->keluar, 2, '.', '') : null,
        ])->all();
    }
}
