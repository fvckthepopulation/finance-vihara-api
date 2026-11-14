<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Pembayaran;
use App\Models\Penerimaan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pelepasan pembatasan aset neto (FV-55, ISAK 35).
 *
 * Donasi dengan pembatasan dicatat sebagai aset neto DENGAN pembatasan. Saat
 * dananya dipakai sesuai tujuannya, pembatasannya dilepas: aset neto DP
 * berkurang, aset neto TP bertambah sebesar beban itu. Tanpa pelepasan,
 * aset neto DP terus menumpuk sementara TP bisa minus — padahal dananya
 * sudah dipakai persis seperti yang diminta donatur.
 *
 * Jurnalnya: Dr 3200 Aset Neto Dengan Pembatasan / Kr 3100 Aset Neto Tanpa
 * Pembatasan. Ditulis ke buku besar, bukan dihitung di laporan saja, supaya
 * angkanya bisa ditelusuri dan dibalik seperti transaksi lain.
 *
 * PER JENIS DANA, bukan satu saldo DP gabungan: beban Bhikkhu hanya boleh
 * melepas Dana Bhikkhu, beban Perayaan hanya Dana Perayaan. Akun pendapatan
 * 41x0/42x0/43x0 tidak membedakan keduanya (dua-duanya "dengan pembatasan"),
 * jadi jenisnya dibaca dari KATEGORI transaksinya, bukan dari kode akun.
 */
class PelepasanPembatasanService
{
    /** Akun aset neto. */
    private const AKUN_DP = '3200';

    private const AKUN_TP = '3100';

    /** Penanda jurnal pelepasan, dipakai juga saat membalik. */
    public const REFERENSI = 'pelepasan_pembatasan';

    /**
     * Pasangan kategori beban → kategori dana terikat yang dilepaskannya.
     *
     * Diturunkan dari bagan akun: 5100 Beban Kegiatan Bhikkhu dibiayai Dana
     * Bhikkhu, 5200 Beban Kegiatan Perayaan dibiayai Dana Perayaan. Kategori
     * beban lain (operasional, paramita) memang tidak dibiayai dana terikat,
     * jadi tidak melepas apa pun.
     */
    private const PASANGAN = [
        'biaya_bhikkhu' => 'dana_bhikkhu',
        'biaya_perayaan' => 'dana_perayaan',
    ];

    /**
     * Catat pelepasan untuk satu pembayaran yang baru saja diposting.
     *
     * Dipanggil DI DALAM transaksi database yang sama dengan posting
     * bebannya, supaya tidak pernah ada beban terposting tanpa pelepasan
     * yang menyertainya (atau sebaliknya).
     */
    public static function untukPembayaran(Pembayaran $pembayaran, User $user): ?JournalEntry
    {
        $danaKategori = self::PASANGAN[$pembayaran->category] ?? null;
        if ($danaKategori === null) {
            return null;
        }

        $beban = (float) $pembayaran->amount;
        if ($beban <= 0) {
            return null;
        }

        $tersedia = self::saldoTerikat($danaKategori, $pembayaran->vihara_id);
        // Beban yang melebihi saldo dana terikat hanya dilepas sebesar
        // saldonya; sisanya memang mengurangi TP (FV-55 poin 4).
        $dilepas = min($beban, $tersedia);
        if ($dilepas <= 0) {
            return null;
        }

        $desc = "Pelepasan pembatasan {$pembayaran->code} — "
            . (\App\Models\TransactionCategory::labelPenerimaan($danaKategori) ?? $danaKategori);

        $jurnal = JournalEntry::create([
            'entity_id' => $pembayaran->entity_id,
            'vihara_id' => $pembayaran->vihara_id,
            'entry_date' => $pembayaran->date,
            'reference_type' => self::REFERENSI,
            'reference_id' => $pembayaran->id,
            'description' => $desc,
            'total_debit' => $dilepas,
            'total_credit' => $dilepas,
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by_user_id' => $user->id,
        ]);

        JournalLine::create([
            'journal_entry_id' => $jurnal->id,
            'account_code' => self::AKUN_DP,
            'debit' => $dilepas,
            'credit' => 0,
            'description' => $desc,
        ]);
        JournalLine::create([
            'journal_entry_id' => $jurnal->id,
            'account_code' => self::AKUN_TP,
            'debit' => 0,
            'credit' => $dilepas,
            'description' => $desc,
        ]);

        return $jurnal;
    }

    /**
     * Sisa dana terikat satu jenis yang belum dilepas, untuk satu vihara.
     *
     * = penerimaan posted berkategori itu − pelepasan yang sudah dicatat
     *   untuk kategori beban pasangannya.
     *
     * Pembatalan ikut terhitung dengan sendirinya: penerimaan yang dibatalkan
     * berstatus cancelled, dan jurnal pelepasan yang dibalik punya pasangan
     * pembalik yang nilainya saling meniadakan.
     */
    public static function saldoTerikat(string $danaKategori, ?int $viharaId): float
    {
        $masuk = (float) Penerimaan::query()
            ->where('status', 'posted')
            ->where('category', $danaKategori)
            ->when($viharaId !== null, fn ($q) => $q->where('vihara_id', $viharaId))
            ->sum('amount');

        $bebanKategori = array_search($danaKategori, self::PASANGAN, true);
        if ($bebanKategori === false) {
            return $masuk;
        }

        // Nilai pelepasan dibaca dari jurnalnya, bukan dari bebannya: yang
        // dilepas bisa lebih kecil daripada bebannya saat dana terikatnya
        // tidak cukup.
        $sudahDilepas = (float) DB::table('journal_entries as j')
            ->join('pembayaran as p', 'p.id', '=', 'j.reference_id')
            ->where('j.reference_type', self::REFERENSI)
            ->where('j.status', 'posted')
            ->where('p.category', $bebanKategori)
            ->when($viharaId !== null, fn ($q) => $q->where('j.vihara_id', $viharaId))
            ->sum('j.total_debit');

        return max(0.0, $masuk - $sudahDilepas);
    }

    /**
     * Balikkan pelepasan saat bebannya dibatalkan.
     *
     * Tanpa ini, pembatasan tetap terlepas padahal bebannya sudah dibalik:
     * aset neto DP kekurangan dan TP kelebihan selamanya.
     */
    public static function balikkan(Pembayaran $pembayaran, User $user, string $alasan): ?JournalEntry
    {
        $asal = self::jurnalMilik($pembayaran);
        if ($asal === null) {
            return null;
        }

        $desc = "Pembalik pelepasan {$pembayaran->code} — {$alasan}";
        $nilai = (float) $asal->total_debit;

        $pembalik = JournalEntry::create([
            'entity_id' => $asal->entity_id,
            'vihara_id' => $asal->vihara_id,
            'entry_date' => $asal->entry_date,
            'reference_type' => self::REFERENSI.'_void',
            'reference_id' => $pembayaran->id,
            'description' => $desc,
            'total_debit' => $nilai,
            'total_credit' => $nilai,
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by_user_id' => $user->id,
        ]);

        // Terbalik dari aslinya: TP kembali berkurang, DP kembali bertambah.
        JournalLine::create([
            'journal_entry_id' => $pembalik->id,
            'account_code' => self::AKUN_TP,
            'debit' => $nilai,
            'credit' => 0,
            'description' => $desc,
        ]);
        JournalLine::create([
            'journal_entry_id' => $pembalik->id,
            'account_code' => self::AKUN_DP,
            'debit' => 0,
            'credit' => $nilai,
            'description' => $desc,
        ]);

        // Jurnal pelepasannya sendiri ditandai dibatalkan supaya tidak ikut
        // terhitung lagi sebagai "sudah dilepas".
        $asal->update(['status' => 'cancelled']);

        return $pembalik;
    }

    /** Jurnal pelepasan milik sebuah pembayaran, kalau ada. */
    public static function jurnalMilik(Pembayaran $pembayaran): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('reference_type', self::REFERENSI)
            ->where('reference_id', $pembayaran->id)
            ->where('status', 'posted')
            ->first();
    }
}
