<?php

namespace App\Services;

use App\Models\BankStatementLine;
use App\Models\ReconciliationRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Auto-matcher rekonsiliasi bank: baris rekening koran vs dokumen sistem
 * (penerimaan / pembayaran / topup kas kecil) yang posted lewat rekening
 * fisik run tersebut dalam periode run.
 *
 * Aturan (diadaptasi dari HomeMade):
 * - hanya baris & dokumen yang belum matched; arah dana harus sesuai
 *   (line.debit>0 = uang masuk ↔ dokumen 'masuk');
 * - jendela tanggal ±3 hari;
 * - 'cocok'  bila |selisih| ≤ max(Rp1, 0,5% nominal sistem);
 * - 'selisih' bila |selisih| ≤ 2% (kasus biaya admin bank);
 * - di atas 2% ditolak; kandidat terbaik = selisih terkecil, lalu jarak
 *   tanggal terkecil; tiap sisi hanya dikonsumsi sekali.
 */
class BankReconMatchingService
{
    public const DATE_WINDOW_DAYS = 3;
    public const SUGGESTION_WINDOW_DAYS = 7;
    private const COCOK_PCT = 0.005;
    private const SELISIH_PCT = 0.02;
    private const SUGGESTION_PCT = 0.05;

    /**
     * Baris sistem untuk sebuah run — dokumen posted rekening itu dalam
     * periode. Shape: {type,id,date,name,amount,direction,matched,matched_line_id}.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function systemRows(ReconciliationRun $run): array
    {
        $bankId = $run->bank_account_id;
        $start = $run->period_start->toDateString();
        $end = $run->period_end->toDateString();

        $rows = [];

        $penerimaan = DB::table('penerimaan')
            ->where('status', 'posted')
            ->where('bank_account_id', $bankId)
            ->whereBetween('date', [$start, $end])
            ->get(['id', 'date', 'name', 'code', 'amount']);
        foreach ($penerimaan as $r) {
            $rows[] = self::row('penerimaan', $r->id, $r->date, $r->name ?? $r->code, (float) $r->amount, 'masuk');
        }

        $pembayaran = DB::table('pembayaran')
            ->where('status', 'posted')
            ->where('bank_account_id', $bankId)
            ->whereBetween('date', [$start, $end])
            ->get(['id', 'date', 'name', 'code', 'amount']);
        foreach ($pembayaran as $r) {
            $rows[] = self::row('pembayaran', $r->id, $r->date, $r->name ?? $r->code, (float) $r->amount, 'keluar');
        }

        $topups = DB::table('petty_cash_movements as m')
            ->leftJoin('petty_cash_pics as p', 'p.id', '=', 'm.pic_id')
            ->where('m.status', 'posted')
            ->where('m.subtype', 'topup')
            ->where('m.source_bank_account_id', $bankId)
            ->whereBetween('m.movement_date', [$start, $end])
            ->get(['m.id', 'm.movement_date as date', 'm.amount', 'p.name as pic_name', 'm.recipient']);
        foreach ($topups as $r) {
            $pic = $r->pic_name ?? $r->recipient ?? '-';
            $rows[] = self::row('petty_topup', $r->id, $r->date, "Pengisian Kas Kecil — {$pic}", (float) $r->amount, 'keluar');
        }

        // Flag matched: ada baris koran yang menunjuk (matched_type, matched_id).
        $matchedMap = $run->bankLines()
            ->whereNotNull('matched_type')
            ->get(['id', 'matched_type', 'matched_id'])
            ->mapWithKeys(fn ($l) => ["{$l->matched_type}:{$l->matched_id}" => $l->id]);

        foreach ($rows as &$row) {
            $lineId = $matchedMap["{$row['type']}:{$row['id']}"] ?? null;
            $row['matched'] = $lineId !== null;
            $row['matched_line_id'] = $lineId;
        }
        unset($row);

        usort($rows, fn ($a, $b) => [$a['date'], $a['type'], $a['id']] <=> [$b['date'], $b['type'], $b['id']]);

        return $rows;
    }

    /** Cari satu baris sistem dari daftar run (untuk validasi manual match). */
    public static function findSystemRow(ReconciliationRun $run, string $type, int $id): ?array
    {
        foreach (self::systemRows($run) as $row) {
            if ($row['type'] === $type && (int) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Preview auto-match (tidak disimpan):
     * {matched:[{system,line,diff,status}], only_system:[...], only_koran:[...]}.
     */
    public static function preview(ReconciliationRun $run, ?array $systemRows = null): array
    {
        $systemRows ??= self::systemRows($run);

        $lines = $run->bankLines()->orderBy('date')->orderBy('id')->get();
        $freeLines = $lines->filter(fn ($l) => $l->matched_type === null)->values();
        $freeRows = array_values(array_filter($systemRows, fn ($r) => ! $r['matched']));

        $used = [];
        $matched = [];
        $onlyKoran = [];

        foreach ($freeLines as $line) {
            $lineAmount = (float) ($line->debit > 0 ? $line->debit : $line->credit);
            $direction = $line->debit > 0 ? 'masuk' : 'keluar';
            $best = null;

            foreach ($freeRows as $idx => $row) {
                if (isset($used[$idx]) || $row['direction'] !== $direction) {
                    continue;
                }
                $days = (int) round(abs(Carbon::parse($row['date'])->diffInDays($line->date, false)));
                if ($days > self::DATE_WINDOW_DAYS) {
                    continue;
                }
                $sysAmount = (float) $row['amount'];
                $diff = abs($lineAmount - $sysAmount);
                $status = self::classify($diff, $sysAmount);
                if ($status === null) {
                    continue;
                }
                if ($best === null || $diff < $best['diff']
                    || ($diff === $best['diff'] && $days < $best['days'])) {
                    $best = ['idx' => $idx, 'diff' => $diff, 'days' => $days, 'status' => $status];
                }
            }

            if ($best !== null) {
                $used[$best['idx']] = true;
                $matched[] = [
                    'system' => $freeRows[$best['idx']],
                    'line' => self::lineArray($line),
                    'diff' => number_format($best['diff'], 2, '.', ''),
                    'status' => $best['status'],
                ];
            } else {
                $onlyKoran[] = self::lineArray($line);
            }
        }

        $onlySystem = array_values(array_filter(
            $freeRows,
            fn ($row, $idx) => ! isset($used[$idx]),
            ARRAY_FILTER_USE_BOTH,
        ));

        return ['matched' => $matched, 'only_system' => $onlySystem, 'only_koran' => $onlyKoran];
    }

    /**
     * Persist preview auto-match saat ini. Idempotent: baris yang sudah
     * matched (atau dokumen yang keburu diambil baris lain) dilewati.
     */
    public static function applyMatches(ReconciliationRun $run): int
    {
        $preview = self::preview($run);
        $applied = 0;

        DB::transaction(function () use ($run, $preview, &$applied) {
            foreach ($preview['matched'] as $pair) {
                $line = BankStatementLine::query()
                    ->where('id', $pair['line']['id'])
                    ->where('reconciliation_run_id', $run->id)
                    ->lockForUpdate()
                    ->first();
                if ($line === null || $line->matched_type !== null) {
                    continue; // sudah matched — skip (idempotent)
                }

                $taken = BankStatementLine::query()
                    ->where('reconciliation_run_id', $run->id)
                    ->where('matched_type', $pair['system']['type'])
                    ->where('matched_id', $pair['system']['id'])
                    ->exists();
                if ($taken) {
                    continue;
                }

                $line->update([
                    'matched_type' => $pair['system']['type'],
                    'matched_id' => $pair['system']['id'],
                    'status' => 'matched',
                ]);
                $applied++;
            }
        });

        return $applied;
    }

    /**
     * Top-3 kandidat sistem untuk satu baris koran, dengan confidence +
     * alasan berbahasa Indonesia. Jendela lebih longgar dari auto-matcher
     * (±7 hari, selisih ≤5%) supaya operator tetap dapat saran.
     */
    public static function suggestions(ReconciliationRun $run, BankStatementLine $line): array
    {
        $lineAmount = (float) ($line->debit > 0 ? $line->debit : $line->credit);
        $direction = $line->debit > 0 ? 'masuk' : 'keluar';

        $candidates = [];
        foreach (self::systemRows($run) as $row) {
            if ($row['matched'] || $row['direction'] !== $direction) {
                continue;
            }
            $days = (int) round(abs(Carbon::parse($row['date'])->diffInDays($line->date, false)));
            if ($days > self::SUGGESTION_WINDOW_DAYS) {
                continue;
            }
            $sysAmount = (float) $row['amount'];
            $diff = abs($lineAmount - $sysAmount);
            $pct = $sysAmount > 0 ? $diff / $sysAmount : 1.0;
            if ($pct > self::SUGGESTION_PCT) {
                continue;
            }

            $exact = $diff <= max(1.0, self::COCOK_PCT * $sysAmount);
            $confidence = match (true) {
                $exact && $days <= self::DATE_WINDOW_DAYS => 'high',
                ($pct <= self::SELISIH_PCT && $days <= self::DATE_WINDOW_DAYS) || $exact => 'medium',
                default => 'low',
            };

            $reasons = [];
            $reasons[] = $exact
                ? 'Jumlah sama persis'
                : 'Selisih Rp' . number_format($diff, 0, ',', '.');
            $reasons[] = $days === 0 ? 'Tanggal sama' : "Selisih {$days} hari";

            $candidates[] = [
                'system' => $row,
                'diff' => number_format($diff, 2, '.', ''),
                'days' => $days,
                'confidence' => $confidence,
                'reasons' => $reasons,
                '_sort' => [$diff, $days],
            ];
        }

        usort($candidates, fn ($a, $b) => $a['_sort'] <=> $b['_sort']);
        $candidates = array_slice($candidates, 0, 3);
        foreach ($candidates as &$c) {
            unset($c['_sort']);
        }

        return $candidates;
    }

    /** Serialisasi baris koran (dipakai preview & endpoint show). */
    public static function lineArray(BankStatementLine $line): array
    {
        return [
            'id' => $line->id,
            'date' => $line->date->toDateString(),
            'description' => $line->description,
            'debit' => number_format((float) $line->debit, 2, '.', ''),
            'credit' => number_format((float) $line->credit, 2, '.', ''),
            'source' => $line->source,
            'status' => $line->status,
            'matched_type' => $line->matched_type,
            'matched_id' => $line->matched_id,
        ];
    }

    /** 'cocok' | 'selisih' | null (tolak). */
    private static function classify(float $diff, float $sysAmount): ?string
    {
        if ($diff <= max(1.0, self::COCOK_PCT * $sysAmount)) {
            return 'cocok';
        }
        if ($sysAmount > 0 && $diff / $sysAmount <= self::SELISIH_PCT) {
            return 'selisih';
        }

        return null;
    }

    private static function row(string $type, int $id, mixed $date, string $name, float $amount, string $direction): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'date' => substr((string) $date, 0, 10),
            'name' => $name,
            'amount' => number_format($amount, 2, '.', ''),
            'direction' => $direction,
        ];
    }
}
