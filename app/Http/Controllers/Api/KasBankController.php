<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PettyCashPic;
use App\Models\Vihara;
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
    /** Kas Penerimaan Langsung — uang tunai yang belum disetor ke bank. */
    private const AKUN_KAS_TUNAI = '1115';

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'vihara_id' => ['sometimes', 'integer', 'exists:viharas,id'],
            'bank_account_id' => ['sometimes', 'integer', 'exists:vihara_bank_accounts,id'],
        ]);

        $viharaIds = ViharaScopeService::readFilterIds($request);
        $bankAccountId = $request->integer('bank_account_id') ?: null;

        return response()->json([
            'bank_accounts' => $this->bankAccounts(
                $viharaIds,
                $request->user()->canSeeYayasanLevel(),
            ),
            'kas_kecil' => $this->kasKecil($viharaIds),
            'kas_tunai' => $this->kasTunai($viharaIds),
            'mutasi' => $this->mutasi($viharaIds, $bankAccountId),
        ]);
    }

    /**
     * Saldo per rekening = penerimaan − pembayaran − topup kas kecil (posted)
     * + net settlement KM.
     *
     * Rekening milik yayasan dan dipakai bersama (FV-36), jadi ada DUA angka:
     *   - `saldo`: saldo utuh rekening, yang harus cocok dengan rekening
     *     koran. Ditampilkan untuk user tingkat yayasan/global.
     *   - `porsi`: masuk − keluar milik tiap vihara di rekening itu. User
     *     berjangkauan vihara hanya berhak atas porsinya sendiri — saldo utuh
     *     memuat dana vihara lain.
     * Porsi boleh minus: vihara bisa memakai dana rekening bersama melebihi
     * yang masuk untuknya. Itu ditandai, bukan disembunyikan.
     */
    private function bankAccounts(?array $viharaIds, bool $tingkatYayasan): array
    {
        $accounts = ViharaBankAccount::query()
            ->with(['entity:id,legal_name', 'viharas:id,name'])
            ->where('active', true)
            ->when($viharaIds !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereDoesntHave('viharas')
                    ->orWhereHas('viharas', fn ($p) => $p->whereIn('viharas.id', $viharaIds)),
            ))
            ->orderBy('bank_name')
            ->orderBy('id')
            ->get();

        $porsi = $this->porsiPerVihara();

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

        return $accounts->map(function (ViharaBankAccount $a) use ($in, $out, $topup, $settleIn, $porsi, $viharaIds, $tingkatYayasan) {
            $saldo = (float) ($in[$a->id] ?? 0) - (float) ($out[$a->id] ?? 0) - (float) ($topup[$a->id] ?? 0)
                + (float) ($settleIn[$a->id] ?? 0);

            $rincian = collect($porsi[$a->id] ?? [])
                ->when($viharaIds !== null, fn ($c) => $c->filter(
                    fn ($r, $viharaId) => in_array((int) $viharaId, $viharaIds, true),
                ))
                ->map(fn ($nilai, $viharaId) => [
                    'vihara_id' => (int) $viharaId,
                    'vihara_name' => $nilai['nama'],
                    'porsi' => number_format($nilai['porsi'], 2, '.', ''),
                ])
                ->values()
                ->all();

            $porsiSaya = array_sum(array_map(fn ($r) => (float) $r['porsi'], $rincian));

            return [
                'id' => $a->id,
                'entity_id' => $a->entity_id,
                'entity_name' => $a->entity?->legal_name,
                // Kosong = dipakai seluruh vihara di yayasan itu.
                'vihara_names' => $a->viharas->pluck('name')->all(),
                'bank_name' => $a->bank_name,
                'account_number' => $a->account_number,
                'account_name' => $a->account_name,
                // Saldo utuh hanya untuk yang berhak melihat seluruh isinya.
                'saldo' => $tingkatYayasan ? number_format($saldo, 2, '.', '') : null,
                'saldo_penuh_tersedia' => $tingkatYayasan,
                'porsi' => number_format($porsiSaya, 2, '.', ''),
                'porsi_per_vihara' => $tingkatYayasan ? $rincian : [],
            ];
        })->values()->all();
    }

    /**
     * Masuk − keluar per (rekening, vihara). Dipakai menghitung porsi tiap
     * vihara pada rekening bersama.
     *
     * @return array<int, array<int, array{nama: string, porsi: float}>>
     */
    private function porsiPerVihara(): array
    {
        $baris = DB::query()->fromSub(
            DB::table('penerimaan')->where('status', 'posted')->whereNotNull('bank_account_id')
                ->selectRaw('bank_account_id, vihara_id, amount AS nilai')
                ->unionAll(
                    DB::table('pembayaran')->where('status', 'posted')->whereNotNull('bank_account_id')
                        ->selectRaw('bank_account_id, vihara_id, -amount AS nilai'),
                )
                ->unionAll(
                    DB::table('petty_cash_movements')->where('status', 'posted')
                        ->where('subtype', 'topup')->whereNotNull('source_bank_account_id')
                        ->selectRaw('source_bank_account_id AS bank_account_id, vihara_id, -amount AS nilai'),
                ),
            'm',
        )
            ->selectRaw('bank_account_id, vihara_id, SUM(nilai) AS porsi')
            ->whereNotNull('vihara_id')
            ->groupBy('bank_account_id', 'vihara_id')
            ->get();

        $nama = Vihara::query()->pluck('name', 'id');

        $out = [];
        foreach ($baris as $r) {
            $out[(int) $r->bank_account_id][(int) $r->vihara_id] = [
                'nama' => $nama[$r->vihara_id] ?? '—',
                'porsi' => (float) $r->porsi,
            ];
        }

        return $out;
    }

    /** Saldo kas kecil per PIC (posted topup − posted usage). */
    private function kasKecil(?array $viharaIds): array
    {
        $pics = PettyCashPic::query()
            ->where('active', true)
            ->when($viharaIds !== null, fn ($q) => $q->whereIn('vihara_id', $viharaIds))
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
    /**
     * Kas tunai (1115 Kas Penerimaan Langsung) — kartu tersendiri (FV-52).
     *
     * Dihitung dari BUKU BESAR, bukan dari dokumen. Dulu dihitung ulang dari
     * penerimaan tunai − pembayaran tunai − topup kas kecil, dan hitungan
     * seperti itu pasti menyimpang begitu ada jurnal lain yang menyentuh 1115
     * — mis. setoran tunai ke bank yang dicatat saat rekonsiliasi. Dengan
     * membaca jurnalnya, angka di Kas & Bank dijamin sama dengan saldo akun
     * 1115 di Laporan Posisi Keuangan (FV-52 poin 8).
     *
     * Uang tunai dipegang masing-masing vihara, jadi selain totalnya
     * dikirim juga rinciannya per vihara.
     *
     * @return array{total: string, per_vihara: list<array{vihara_id: int|null, vihara: string, saldo: string}>}
     */
    private function kasTunai(?array $viharaIds): array
    {
        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->leftJoin('viharas as v', 'v.id', '=', 'je.vihara_id')
            ->where('jl.account_code', self::AKUN_KAS_TUNAI)
            ->whereNotNull('je.posted_at')
            ->where('je.status', 'posted')
            ->when($viharaIds !== null, fn ($q) => $q->whereIn('je.vihara_id', $viharaIds))
            ->groupBy('je.vihara_id', 'v.name')
            ->selectRaw('je.vihara_id, v.name as vihara, SUM(jl.debit - jl.credit) as saldo')
            ->orderBy('v.name')
            ->get();

        $total = 0.0;
        $perVihara = [];
        foreach ($rows as $r) {
            $saldo = (float) $r->saldo;
            $total += $saldo;
            $perVihara[] = [
                'vihara_id' => $r->vihara_id !== null ? (int) $r->vihara_id : null,
                'vihara' => $r->vihara ?? 'Tingkat yayasan',
                'saldo' => number_format($saldo, 2, '.', ''),
            ];
        }

        return [
            'total' => number_format($total, 2, '.', ''),
            'per_vihara' => $perVihara,
        ];
    }

    /**
     * Mutasi bank terakhir (cap 50): penerimaan/pembayaran posted yang lewat
     * rekening + pengisian kas kecil bersumber rekening.
     */
    private function mutasi(?array $viharaIds, ?int $bankAccountId): array
    {
        $scope = function ($q, string $bankColumn) use ($viharaIds, $bankAccountId) {
            $q->where('status', 'posted')->whereNotNull($bankColumn);
            if ($viharaIds !== null) {
                $q->whereIn('vihara_id', $viharaIds);
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
            ->when($viharaIds !== null, fn ($q) => $q->whereIn('vihara_id', $viharaIds))
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
