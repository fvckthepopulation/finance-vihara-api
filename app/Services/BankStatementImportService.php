<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

/**
 * Parser toleran untuk file rekening koran (CSV / XLSX). Deteksi format dari
 * ISI file, bukan ekstensi.
 *
 * Header yang dikenali:
 * - Gaya BCA:     Tanggal / Keterangan / Mutasi / CR-DB (CR = uang masuk).
 * - Gaya Mandiri: Tanggal / Keterangan / Debet / Kredit — konvensi bank:
 *                 Debet = uang KELUAR rekening, Kredit = uang MASUK.
 * - Generic:      date / description / debit / credit (header Inggris) —
 *                 konvensi penyimpanan kita: debit = MASUK, credit = KELUAR.
 *
 * Tanggal: ISO, d-m-Y, m-d-Y (bila komponen pertama > 12), nama bulan
 * Indonesia/Inggris, serial Excel. Angka: kedua locale ("15.000,50" dan
 * "15,000.50"). Baris yang tanggalnya tak terbaca (mis. "Saldo Awal")
 * dilewati diam-diam.
 *
 * Output: [['date' => 'Y-m-d', 'description' => string,
 *           'masuk' => float, 'keluar' => float], ...]
 */
class BankStatementImportService
{
    /** @return array<int, array{date:string, description:string, masuk:float, keluar:float}> */
    public static function parse(UploadedFile $file): array
    {
        return self::interpret(self::toRows($file));
    }

    /** File → matriks sel mentah. XLSX dikenali dari magic bytes "PK". */
    private static function toRows(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $head = (string) file_get_contents($path, false, null, 0, 4);

        if (str_starts_with($head, 'PK')) {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);

            return $spreadsheet->getActiveSheet()->toArray();
        }

        $text = (string) file_get_contents($path);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text); // strip BOM
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

        // Deteksi delimiter: pemenang hitungan di baris-baris awal non-kosong.
        $counts = [',' => 0, ';' => 0, "\t" => 0];
        $seen = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            foreach ($counts as $d => $_) {
                $counts[$d] += substr_count($line, $d);
            }
            if (++$seen >= 10) {
                break;
            }
        }
        arsort($counts);
        $delimiter = array_key_first($counts);

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = str_getcsv($line, $delimiter, '"', '\\');
        }

        return $rows;
    }

    private static function interpret(array $rows): array
    {
        $map = null;
        $items = [];

        foreach ($rows as $cells) {
            if (! is_array($cells)) {
                continue;
            }
            $cells = array_values($cells);

            if ($map === null) {
                $map = self::detectHeader($cells);
                continue; // baris header (atau pra-header) bukan data
            }

            $date = self::parseDate($cells[$map['date']] ?? null);
            if ($date === null) {
                continue; // "Saldo Awal", footer, dsb.
            }
            $description = trim((string) ($cells[$map['desc']] ?? '')) ?: '-';

            $masuk = 0.0;
            $keluar = 0.0;
            if ($map['style'] === 'crdb') {
                $amount = abs(self::parseNumber($cells[$map['amount']] ?? null));
                $flag = strtoupper(trim((string) ($cells[$map['crdb']] ?? '')));
                if (str_contains($flag, 'CR') || $flag === 'K') {
                    $masuk = $amount;
                } else {
                    $keluar = $amount;
                }
            } else {
                $d = abs(self::parseNumber($cells[$map['debit']] ?? null));
                $c = abs(self::parseNumber($cells[$map['credit']] ?? null));
                if ($map['style'] === 'bank_id') {
                    // Konvensi rekening koran bank: Kredit = masuk, Debet = keluar.
                    $masuk = $c;
                    $keluar = $d;
                } else {
                    // Generic (konvensi penyimpanan): debit = masuk.
                    $masuk = $d;
                    $keluar = $c;
                }
            }

            $items[] = compact('date', 'description', 'masuk', 'keluar');
        }

        return $items;
    }

    /**
     * Kenali baris header dan petakan kolom. Null = bukan header.
     *
     * @return array{style:string, date:int, desc:int, amount?:int, crdb?:int, debit?:int, credit?:int}|null
     */
    private static function detectHeader(array $cells): ?array
    {
        $lower = array_map(fn ($c) => strtolower(trim((string) $c)), $cells);

        $find = function (string $pattern) use ($lower): ?int {
            foreach ($lower as $i => $c) {
                if ($c !== '' && preg_match($pattern, $c)) {
                    return $i;
                }
            }

            return null;
        };

        $dateCol = $find('/^(tanggal|tgl)\b|^(posting\s*)?date\b|^value\s*date\b/');
        $descCol = $find('/keterangan|deskripsi|description|uraian|remark/');
        if ($dateCol === null || $descCol === null) {
            return null;
        }

        $indonesian = preg_match('/tanggal|tgl/', $lower[$dateCol])
            || preg_match('/keterangan|uraian/', $lower[$descCol]);

        // Gaya BCA: kolom Mutasi + flag CR/DB.
        $crdbCol = $find('/^(cr\s*[\/\-]?\s*db|db\s*[\/\-]?\s*cr|crdb|dbcr|d\/k|k\/d)$/');
        $amountCol = $find('/mutasi|amount|jumlah|nominal/');
        if ($crdbCol !== null && $amountCol !== null) {
            return ['style' => 'crdb', 'date' => $dateCol, 'desc' => $descCol, 'amount' => $amountCol, 'crdb' => $crdbCol];
        }

        // Kolom debet/kredit terpisah (Mandiri / generic).
        $debitCol = $find('/debet|debit|^db$/');
        $creditCol = $find('/kredit|credit|^cr$/');
        if ($debitCol !== null && $creditCol !== null) {
            $style = ($indonesian || preg_match('/debet/', $lower[$debitCol]) || preg_match('/kredit/', $lower[$creditCol]))
                ? 'bank_id'
                : 'generic';

            return ['style' => $style, 'date' => $dateCol, 'desc' => $descCol, 'debit' => $debitCol, 'credit' => $creditCol];
        }

        return null;
    }

    /** Tanggal toleran → 'Y-m-d' atau null. */
    public static function parseDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        // Serial Excel (bila cell tidak terformat sebagai tanggal).
        if (is_numeric($value) && ! is_string($value) || (is_string($value) && preg_match('/^\d{5}(\.\d+)?$/', trim($value)))) {
            $serial = (float) $value;
            if ($serial > 25569 && $serial < 60000) { // 1970..2064
                return gmdate('Y-m-d', (int) round(($serial - 25569) * 86400));
            }
        }

        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) {
            return self::ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2,4})$/', $v, $m)) {
            [$a, $b, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $y = $y < 100 ? 2000 + $y : $y;
            // Default Indonesia d-m-Y; kalau komponen pertama jelas bulan → m-d-Y.
            [$day, $month] = $a > 12 ? [$a, $b] : ($b > 12 ? [$b, $a] : [$a, $b]);

            return self::ymd($y, $month, $day);
        }

        // Nama bulan Indonesia / Inggris: "14 Jul 2026", "14 Agustus 2026", "14-Des-26".
        if (preg_match('/^(\d{1,2})[\s\-\/]+([a-z]+)[\s\-\/,.]+(\d{2,4})$/i', $v, $m)) {
            $months = [
                'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mei' => 5, 'may' => 5,
                'jun' => 6, 'jul' => 7, 'agu' => 8, 'ags' => 8, 'agt' => 8, 'aug' => 8,
                'sep' => 9, 'okt' => 10, 'oct' => 10, 'nov' => 11, 'des' => 12, 'dec' => 12,
            ];
            $key = strtolower(substr($m[2], 0, 3));
            if (isset($months[$key])) {
                $y = (int) $m[3];

                return self::ymd($y < 100 ? 2000 + $y : $y, $months[$key], (int) $m[1]);
            }
        }

        return null;
    }

    /** Angka toleran kedua locale ("15.000,50" / "15,000.50" / "Rp 15.000") → float. */
    public static function parseNumber(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $s = trim((string) $value);
        $negative = str_contains($s, '-') || (str_starts_with($s, '(') && str_ends_with($s, ')'));
        $s = preg_replace('/[^0-9,.]/', '', $s) ?? '';
        if ($s === '') {
            return 0.0;
        }

        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');
        if ($lastComma !== false && $lastDot !== false) {
            // Pemisah desimal = yang muncul paling akhir.
            if ($lastComma > $lastDot) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($lastComma !== false) {
            // Hanya koma: desimal bila 1-2 digit di belakang, selain itu ribuan.
            $s = preg_match('/,\d{1,2}$/', $s)
                ? str_replace(',', '.', $s)
                : str_replace(',', '', $s);
        } elseif ($lastDot !== false) {
            // Hanya titik: pola ribuan Indonesia "15.000" / "1.234.567" → hapus.
            if (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
                $s = str_replace('.', '', $s);
            }
        }

        $n = (float) $s;

        return $negative ? -$n : $n;
    }

    private static function ymd(int $y, int $m, int $d): ?string
    {
        if (! checkdate($m, $d, $y)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }
}
