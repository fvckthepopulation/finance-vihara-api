<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Pembatalan transaksi yang sudah terposting, lewat JURNAL PEMBALIK.
 *
 * Bukan penghapusan. Baris aslinya tetap ada, jurnal aslinya tetap ada, dan
 * di atasnya ditambahkan jurnal yang persis kebalikannya. Dengan begitu:
 *
 *   - jejaknya utuh: siapa membatalkan, kapan, dan alasannya;
 *   - laporan berbasis buku besar otomatis netral, karena asli dan
 *     pembaliknya saling meniadakan — tidak ada laporan yang perlu diubah;
 *   - laporan berbasis tabel transaksi tetap benar karena status baris
 *     asli dipindahkan ke 'cancelled', jadi tidak ikut dijumlahkan.
 *
 * Tanggal pembalik sengaja MENGIKUTI tanggal asli, bukan hari ini. Kalau
 * pembalik didorong ke periode berjalan, periode lama ikut berubah angkanya
 * padahal laporannya mungkin sudah terbit. Konsekuensinya: transaksi di
 * periode yang sudah dikunci tidak bisa dibatalkan — dan itu memang
 * seharusnya, karena periode terkunci berarti bukunya sudah ditutup.
 */
class VoidService
{
    /**
     * @param  Model  $record  pembayaran / penerimaan / petty cash movement / jurnal
     * @param  string $label   kata yang dipakai di pesan, mis. "Pembayaran"
     * @return array{journal: JournalEntry}
     */
    public static function void(Model $record, User $user, string $reason, string $label): array
    {
        if ($record->voided_at !== null) {
            abort(422, "{$label} ini sudah dibatalkan.");
        }
        // Jurnal yang LAHIR dari dokumen lain tidak boleh dibatalkan sendiri.
        // Membalik jurnalnya saja membuat buku besar berubah sementara
        // dokumen sumbernya tetap berstatus posted — angkanya jadi tidak
        // cocok dengan daftar transaksi. Termasuk membalik jurnal pembalik,
        // yang justru menghidupkan kembali transaksi yang sudah dibatalkan.
        if ($record instanceof JournalEntry
            && $record->reference_type !== null
            && $record->reference_type !== 'manual') {
            abort(422, 'Jurnal ini dihasilkan dari transaksi lain. Batalkan transaksi sumbernya, bukan jurnalnya.');
        }

        if ($record->status !== 'posted') {
            abort(422, "Hanya {$label} berstatus posted yang bisa dibatalkan. Yang masih draft cukup dihapus.");
        }

        $asal = $record instanceof JournalEntry ? $record : $record->journalEntry;
        if (! $asal) {
            abort(422, "{$label} ini tidak punya jurnal, jadi tidak ada yang bisa dibalik.");
        }

        $tanggal = $record instanceof JournalEntry ? $record->entry_date : $record->date;

        // Periode terkunci = buku sudah ditutup. Melemparnya ke sini supaya
        // pesannya jelas, bukan gagal di tengah transaksi database.
        PeriodLockService::guard($record->entity_id, $tanggal, 'date');

        return DB::transaction(function () use ($record, $user, $reason, $label, $asal, $tanggal) {
            $kode = $record->code ?? "JE#{$asal->id}";
            $desc = "Pembalik {$kode} — {$reason}";

            $pembalik = JournalEntry::create([
                'entity_id' => $asal->entity_id,
                'vihara_id' => $asal->vihara_id,
                'entry_date' => $tanggal,
                'reference_type' => $asal->reference_type ? $asal->reference_type . '_void' : 'void',
                'reference_id' => $asal->reference_id ?? $asal->id,
                'description' => $desc,
                'total_debit' => $asal->total_credit,
                'total_credit' => $asal->total_debit,
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by_user_id' => $user->id,
            ]);

            // Inti pembalikan: debit dan kredit ditukar, nominal sama persis.
            foreach ($asal->lines as $baris) {
                JournalLine::create([
                    'journal_entry_id' => $pembalik->id,
                    'account_code' => $baris->account_code,
                    'debit' => $baris->credit,
                    'credit' => $baris->debit,
                    'description' => $desc,
                ]);
            }

            $record->forceFill([
                'status' => 'cancelled',
                'voided_at' => now(),
                'voided_by_user_id' => $user->id,
                'void_reason' => $reason,
                'void_journal_entry_id' => $pembalik->id,
            ])->save();

            return ['journal' => $pembalik];
        });
    }
}
