<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Vihara;
use App\Services\ViharaScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unduhan Jurnal & Buku Besar dalam .xlsx (FV-59).
 *
 * Dua sheet, sesuai nama halamannya:
 *   "Jurnal Umum" — satu baris per BARIS jurnal, bukan per jurnal, supaya
 *                   bisa dijumlah dan disaring di Excel.
 *   "Buku Besar"  — rekap per akun: saldo awal, mutasi, saldo akhir.
 *
 * Debit dan kredit ditulis sebagai ANGKA dengan format ribuan, bukan teks:
 * laporan yang angkanya tidak bisa dijumlah di Excel tidak ada gunanya bagi
 * akuntan, dan itulah alasan fitur ini diminta.
 *
 * Saringannya sama persis dengan yang sedang aktif di layar, dan jangkauan
 * aksesnya tetap diurus ViharaScopeService — unduhan tidak boleh jadi pintu
 * belakang untuk melihat vihara lain.
 */
class JurnalExportController extends Controller
{
    private const FORMAT_RP = '#,##0';

    /** Jumlah baris bukan posted dari sheet Jurnal Umum, untuk sheet Info. */
    private int $bukanPosted = 0;

    public function __invoke(Request $request): StreamedResponse
    {
        $request->validate([
            'source' => ['sometimes', 'nullable', 'string', 'max:40'],
            'status' => ['sometimes', 'nullable', 'string', 'max:20'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'vihara_id' => ['sometimes', 'nullable', 'integer', 'exists:viharas,id'],
        ]);

        $baris = $this->barisJurnal($request);
        $namaAkun = Account::query()->pluck('name', 'code');

        $spreadsheet = new Spreadsheet();
        $this->sheetJurnal($spreadsheet, $request, $baris, $namaAkun);
        $this->sheetBukuBesar($spreadsheet, $baris, $namaAkun);
        $this->sheetInfo($spreadsheet, $request, count($baris), $this->bukanPosted);
        $spreadsheet->setActiveSheetIndex(0);

        $namaFile = $this->namaFile($request);

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $namaFile, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Baris jurnal ter-filter, satu baris per journal_line. */
    private function barisJurnal(Request $request): array
    {
        $q = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->leftJoin('viharas as v', 'v.id', '=', 'je.vihara_id')
            ->leftJoin('pembayaran as p', function ($j) {
                $j->on('p.id', '=', 'je.reference_id')->where('je.reference_type', '=', 'pembayaran');
            })
            ->leftJoin('penerimaan as r', function ($j) {
                $j->on('r.id', '=', 'je.reference_id')->where('je.reference_type', '=', 'penerimaan');
            })
            ->leftJoin('users as uc', 'uc.id', '=', 'je.created_by_user_id')
            ->leftJoin('users as up', 'up.id', '=', 'je.posted_by_user_id')
            ->select([
                'je.id as je_id', 'je.entry_date', 'je.reference_type', 'je.description',
                'je.status', 'je.posted_at', 'v.name as vihara_name',
                'jl.account_code', 'jl.debit', 'jl.credit', 'jl.description as line_description',
                DB::raw('COALESCE(p.code, r.code) as kode_transaksi'),
                'uc.name as dibuat_oleh', 'up.name as diposting_oleh',
            ])
            ->orderBy('je.entry_date')
            ->orderBy('je.id')
            ->orderBy('jl.id');

        // Jangkauan akses: jurnal tingkat yayasan hanya ikut bagi pemegang
        // akses yayasan ke atas, dan hanya milik yayasannya sendiri (FV-62).
        // Sebelumnya user tingkat vihara pun ikut menerima jurnal pusat.
        $ids = ViharaScopeService::readFilterIds($request);
        if ($ids !== null) {
            $user = $request->user();
            $q->where(function ($w) use ($ids, $user) {
                $w->whereIn('je.vihara_id', $ids);
                ViharaScopeService::orYayasanLevel($w, $user, 'je.vihara_id', 'je.entity_id');
            });
        }
        if ($vihara = $request->integer('vihara_id')) {
            $q->where('je.vihara_id', $vihara);
        }
        if ($source = $request->string('source')->toString()) {
            $q->where('je.reference_type', $source);
        }
        if ($status = $request->string('status')->toString()) {
            $q->where('je.status', $status);
        }
        if ($from = $request->string('from')->toString()) {
            $q->whereDate('je.entry_date', '>=', $from);
        }
        if ($to = $request->string('to')->toString()) {
            $q->whereDate('je.entry_date', '<=', $to);
        }
        if ($cari = $request->string('q')->toString()) {
            $like = '%'.$cari.'%';
            $q->where(function ($w) use ($like) {
                $w->where('je.description', 'ilike', $like)
                    ->orWhere('je.reference', 'ilike', $like)
                    ->orWhere('p.code', 'ilike', $like)
                    ->orWhere('r.code', 'ilike', $like);
            });
        }

        return $q->get()->all();
    }

    /**
     * Sheet "Jurnal Umum", disusun seperti Riwayat Jurnal Home Made (FV-72):
     * judul kolom di baris 1 (langsung bisa difilter/di-pivot), Debit &
     * Kredit tepat setelah akun supaya terlihat tanpa menggulir jauh, kolom
     * deskripsi dibatasi lebarnya, tanggal sebagai tanggal Excel. Keterangan
     * unduhan pindah ke sheet "Info". TOTAL hanya menjumlah jurnal posted —
     * draft ikut tampil (bertanda di kolom Status) tetapi tidak ikut dijumlah.
     */
    private function sheetJurnal(Spreadsheet $s, Request $request, array $baris, $namaAkun): void
    {
        $sheet = $s->getActiveSheet();
        $sheet->setTitle('Jurnal Umum');

        // [judul, lebar]
        $kolom = [
            'A' => ['Tanggal', 12], 'B' => ['No. Jurnal', 11], 'C' => ['No. Transaksi', 15],
            'D' => ['Kode Akun', 10], 'E' => ['Nama Akun', 32], 'F' => ['Debit', 15], 'G' => ['Kredit', 15],
            'H' => ['Deskripsi Baris', 60], 'I' => ['Deskripsi Jurnal', 60], 'J' => ['Sumber', 13],
            'K' => ['Vihara', 28], 'L' => ['Status', 10], 'M' => ['Dibuat oleh', 20], 'N' => ['Diposting oleh', 20],
        ];
        foreach ($kolom as $huruf => [$judul, $lebar]) {
            $sheet->setCellValue("{$huruf}1", $judul);
            $sheet->getColumnDimension($huruf)->setWidth($lebar);
        }
        $sheet->getStyle('A1:N1')->getFont()->setBold(true);
        $sheet->getStyle('A1:N1')->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EFE7DD');

        $r = 2;
        $totalDebit = 0.0;
        $totalKredit = 0.0;
        $bukanPosted = 0;
        foreach ($baris as $b) {
            $debit = (float) $b->debit;
            $kredit = (float) $b->credit;
            if (self::terposting($b)) {
                $totalDebit += $debit;
                $totalKredit += $kredit;
            } else {
                $bukanPosted++;
            }

            $sheet->setCellValue("A{$r}", ExcelDate::PHPToExcel(new \DateTimeImmutable(substr((string) $b->entry_date, 0, 10))));
            $sheet->setCellValue("B{$r}", 'JE-'.$b->je_id);
            $sheet->setCellValue("C{$r}", $b->kode_transaksi ?? '');
            $sheet->setCellValueExplicit("D{$r}", (string) $b->account_code, DataType::TYPE_STRING);
            $sheet->setCellValue("E{$r}", $namaAkun[$b->account_code] ?? '');
            $sheet->setCellValue("F{$r}", $debit);
            $sheet->setCellValue("G{$r}", $kredit);
            $sheet->setCellValue("H{$r}", $b->line_description);
            $sheet->setCellValue("I{$r}", $b->description);
            $sheet->setCellValue("J{$r}", $b->reference_type ?? 'manual');
            $sheet->setCellValue("K{$r}", $b->vihara_name ?? 'Tingkat yayasan');
            $sheet->setCellValue("L{$r}", $b->status);
            $sheet->setCellValue("M{$r}", $b->dibuat_oleh ?? '');
            $sheet->setCellValue("N{$r}", $b->diposting_oleh ?? '');
            $r++;
        }
        $barisAkhirData = $r - 1;

        if ($baris === []) {
            $sheet->setCellValue("A{$r}", 'Tidak ada data untuk filter ini.');
            $r++;
        }

        $sheet->setCellValue("E{$r}", 'TOTAL (Terposting)');
        $sheet->setCellValue("F{$r}", $totalDebit);
        $sheet->setCellValue("G{$r}", $totalKredit);
        $sheet->getStyle("E{$r}:G{$r}")->getFont()->setBold(true);
        if ($bukanPosted > 0) {
            $sheet->setCellValue("H{$r}", "{$bukanPosted} baris belum diposting (mis. draft) tidak ikut dijumlah.");
            $sheet->getStyle("H{$r}")->getFont()->setItalic(true);
        }

        $sheet->getStyle("A2:A{$r}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $sheet->getStyle("F2:G{$r}")->getNumberFormat()->setFormatCode(self::FORMAT_RP);
        $sheet->getStyle("F2:G{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("H2:I{$r}")->getAlignment()->setWrapText(true);
        $sheet->getStyle("A2:N{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        $sheet->freezePane('A2');
        if ($baris !== []) {
            $sheet->setAutoFilter("A1:N{$barisAkhirData}");
        }

        $this->bukanPosted = $bukanPosted;
    }

    /**
     * Jurnal yang masuk buku besar: sudah diposting. Jurnal yang dibatalkan
     * (status cancelled) tetap terposting dan diimbangi jurnal pembaliknya —
     * aturan yang sama dengan laporan keuangan (posted_at terisi).
     */
    private static function terposting(object $b): bool
    {
        return $b->posted_at !== null;
    }

    /** Keterangan unduhan, dipisah dari tabel supaya tabel mulai di baris 1. */
    private function sheetInfo(Spreadsheet $s, Request $request, int $jumlahBaris, int $bukanPosted): void
    {
        $sheet = $s->createSheet();
        $sheet->setTitle('Info');
        $isi = [
            ['Jurnal Umum', ''],
            ['Lingkup', $this->keteranganLingkup($request)],
            ['Periode', $this->keteranganPeriode($request)],
            ['Diunduh', now()->format('d/m/Y H:i').' oleh '.($request->user()->name ?? '-')],
            ['Jumlah baris', (string) $jumlahBaris],
            ['Catatan', $bukanPosted > 0
                ? "TOTAL di sheet Jurnal Umum dan sheet Buku Besar hanya menjumlah jurnal terposting (sama dengan laporan keuangan); {$bukanPosted} baris belum diposting (mis. draft) ditampilkan tetapi tidak dijumlah. Jurnal yang dibatalkan tetap dihitung bersama jurnal pembaliknya, sehingga saling meniadakan."
                : 'TOTAL di sheet Jurnal Umum dan sheet Buku Besar menjumlah jurnal terposting (sama dengan laporan keuangan). Jurnal yang dibatalkan dihitung bersama jurnal pembaliknya, sehingga saling meniadakan.'],
        ];
        foreach ($isi as $i => [$a, $b]) {
            $sheet->setCellValue('A'.($i + 1), $a);
            $sheet->setCellValue('B'.($i + 1), $b);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2:A6')->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setWidth(16);
        $sheet->getColumnDimension('B')->setWidth(90);
    }

    /** Sheet kedua: rekap per akun (saldo awal selalu 0 — lihat catatan). */
    private function sheetBukuBesar(Spreadsheet $s, array $baris, $namaAkun): void
    {
        $sheet = $s->createSheet();
        $sheet->setTitle('Buku Besar');

        $per = [];
        foreach ($baris as $b) {
            // Buku besar hanya berisi jurnal terposting; draft tidak (FV-72).
            if (! self::terposting($b)) {
                continue;
            }
            $kode = (string) $b->account_code;
            $per[$kode] ??= ['debit' => 0.0, 'kredit' => 0.0];
            $per[$kode]['debit'] += (float) $b->debit;
            $per[$kode]['kredit'] += (float) $b->credit;
        }
        ksort($per);

        $sheet->setCellValue('A1', 'Buku Besar — rekap per akun');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', 'Mutasi jurnal terposting dalam rentang yang sama dengan sheet Jurnal Umum (draft tidak dihitung).');

        foreach (['A4' => 'Kode Akun', 'B4' => 'Nama Akun', 'C4' => 'Debit', 'D4' => 'Kredit', 'E4' => 'Mutasi Bersih'] as $sel => $t) {
            $sheet->setCellValue($sel, $t);
        }
        $sheet->getStyle('A4:E4')->getFont()->setBold(true);
        $sheet->getStyle('A4:E4')->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EFE7DD');

        $r = 5;
        foreach ($per as $kode => $n) {
            $sheet->setCellValueExplicit("A{$r}", $kode, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue("B{$r}", $namaAkun[$kode] ?? '');
            $sheet->setCellValue("C{$r}", $n['debit']);
            $sheet->setCellValue("D{$r}", $n['kredit']);
            $sheet->setCellValue("E{$r}", $n['debit'] - $n['kredit']);
            $r++;
        }

        $sheet->getStyle("C4:E{$r}")->getNumberFormat()->setFormatCode(self::FORMAT_RP);
        foreach (range('A', 'E') as $kolom) {
            $sheet->getColumnDimension($kolom)->setAutoSize(true);
        }
    }

    private function keteranganLingkup(Request $request): string
    {
        if ($vihara = $request->integer('vihara_id')) {
            return 'Vihara: '.(Vihara::whereKey($vihara)->value('name') ?? $vihara);
        }

        return 'Vihara: seluruh vihara dalam jangkauan akses';
    }

    private function keteranganPeriode(Request $request): string
    {
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        if (! $from && ! $to) {
            return 'Periode: seluruh data';
        }

        return 'Periode: '.($from ?: '…').' s/d '.($to ?: '…');
    }

    private function namaFile(Request $request): string
    {
        $vihara = $request->integer('vihara_id')
            ? (Vihara::whereKey($request->integer('vihara_id'))->value('code') ?? 'vihara')
            : 'semua';
        $from = $request->string('from')->toString() ?: 'awal';
        $to = $request->string('to')->toString() ?: now()->toDateString();

        return "Jurnal_{$vihara}_{$from}_sd_{$to}.xlsx";
    }
}
