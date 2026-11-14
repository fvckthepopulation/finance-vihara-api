<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Vihara;
use App\Services\ViharaScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->select([
                'je.id as je_id', 'je.entry_date', 'je.reference_type', 'je.description',
                'je.status', 'v.name as vihara_name',
                'jl.account_code', 'jl.debit', 'jl.credit', 'jl.description as line_description',
                DB::raw('COALESCE(p.code, r.code) as kode_transaksi'),
            ])
            ->orderBy('je.entry_date')
            ->orderBy('je.id')
            ->orderBy('jl.id');

        // Jangkauan akses: jurnal tingkat yayasan ikut terlihat oleh pemegang
        // akses yayasan ke atas, sama seperti di daftarnya.
        $ids = ViharaScopeService::readFilterIds($request);
        if ($ids !== null) {
            $q->where(function ($w) use ($ids) {
                $w->whereIn('je.vihara_id', $ids)->orWhereNull('je.vihara_id');
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

    private function sheetJurnal(Spreadsheet $s, Request $request, array $baris, $namaAkun): void
    {
        $sheet = $s->getActiveSheet();
        $sheet->setTitle('Jurnal Umum');

        $judul = ['Tanggal', 'No. Jurnal', 'Sumber', 'No. Transaksi', 'Vihara', 'Deskripsi Jurnal',
            'Status', 'Kode Akun', 'Nama Akun', 'Deskripsi Baris', 'Debit', 'Kredit'];

        $sheet->setCellValue('A1', 'Jurnal Umum');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', $this->keteranganLingkup($request));
        $sheet->setCellValue('A3', $this->keteranganPeriode($request));
        $sheet->setCellValue('A4', 'Diunduh '.now()->format('d/m/Y H:i').' oleh '.($request->user()->name ?? '-'));

        $barisJudul = 6;
        // PhpSpreadsheet 5.x membuang setCellValueByColumnAndRow(); alamat
        // selnya dirakit sendiri (A..L).
        foreach ($judul as $i => $t) {
            $kolom = chr(ord('A') + $i);
            $sheet->setCellValue("{$kolom}{$barisJudul}", $t);
        }
        $sheet->getStyle("A{$barisJudul}:L{$barisJudul}")->getFont()->setBold(true);
        $sheet->getStyle("A{$barisJudul}:L{$barisJudul}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EFE7DD');

        $r = $barisJudul + 1;
        $totalDebit = 0.0;
        $totalKredit = 0.0;
        foreach ($baris as $b) {
            $debit = (float) $b->debit;
            $kredit = (float) $b->credit;
            $totalDebit += $debit;
            $totalKredit += $kredit;

            $sheet->setCellValue("A{$r}", date('d/m/Y', strtotime($b->entry_date)));
            $sheet->setCellValue("B{$r}", 'JE-'.$b->je_id);
            $sheet->setCellValue("C{$r}", $b->reference_type ?? 'manual');
            $sheet->setCellValue("D{$r}", $b->kode_transaksi ?? '');
            $sheet->setCellValue("E{$r}", $b->vihara_name ?? 'Tingkat yayasan');
            $sheet->setCellValue("F{$r}", $b->description);
            $sheet->setCellValue("G{$r}", $b->status);
            $sheet->setCellValueExplicit("H{$r}", (string) $b->account_code,
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue("I{$r}", $namaAkun[$b->account_code] ?? '');
            $sheet->setCellValue("J{$r}", $b->line_description);
            $sheet->setCellValue("K{$r}", $debit);
            $sheet->setCellValue("L{$r}", $kredit);
            $r++;
        }

        if ($baris === []) {
            $sheet->setCellValue("A{$r}", 'Tidak ada data untuk filter ini.');
            $r++;
        }

        $sheet->setCellValue("J{$r}", 'Total');
        $sheet->setCellValue("K{$r}", $totalDebit);
        $sheet->setCellValue("L{$r}", $totalKredit);
        $sheet->getStyle("J{$r}:L{$r}")->getFont()->setBold(true);

        $sheet->getStyle("K{$barisJudul}:L{$r}")->getNumberFormat()->setFormatCode(self::FORMAT_RP);
        $sheet->getStyle("K{$barisJudul}:L{$r}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        foreach (range('A', 'L') as $kolom) {
            $sheet->getColumnDimension($kolom)->setAutoSize(true);
        }
        $sheet->freezePane('A'.($barisJudul + 1));
    }

    /** Sheet kedua: rekap per akun (saldo awal selalu 0 — lihat catatan). */
    private function sheetBukuBesar(Spreadsheet $s, array $baris, $namaAkun): void
    {
        $sheet = $s->createSheet();
        $sheet->setTitle('Buku Besar');

        $per = [];
        foreach ($baris as $b) {
            $kode = (string) $b->account_code;
            $per[$kode] ??= ['debit' => 0.0, 'kredit' => 0.0];
            $per[$kode]['debit'] += (float) $b->debit;
            $per[$kode]['kredit'] += (float) $b->credit;
        }
        ksort($per);

        $sheet->setCellValue('A1', 'Buku Besar — rekap per akun');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', 'Mutasi dalam rentang yang sama dengan sheet Jurnal Umum.');

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
