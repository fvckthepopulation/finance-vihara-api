<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Siklus draft → pengajuan → persetujuan (FV-20, FV-21, FV-24).
 *
 * Sebelumnya hanya ada dua keadaan nyata: draft dan posted. Tombol "Simpan
 * sebagai Draft" karenanya langsung memasukkan transaksi ke antrean
 * persetujuan — draft dan pengajuan tidak terbedakan. Padahal draft berarti
 * pekerjaan setengah jadi yang belum diproses siapa pun.
 *
 * Keadaan sesudah migrasi ini:
 *
 *   draft      disimpan, belum diajukan. Boleh diubah/dihapus pembuatnya.
 *   submitted  menunggu persetujuan. Masuk antrean approver.
 *   posted     disetujui — jurnalnya dibuat di sini.
 *   rejected   ditolak, lengkap dengan alasan dan siapa yang menolak.
 *   cancelled  dibatalkan sesudah posted (jurnal pembalik, lihat VoidService).
 *
 * Dua hal yang menyertainya:
 *
 * 1. Beberapa kolom dilonggarkan jadi nullable. Draft memang boleh belum
 *    lengkap — itu inti keluhannya — jadi jumlah, kategori, dan akun-akunnya
 *    bisa kosong. Kelengkapannya ditegakkan saat PENGAJUAN dan PERSETUJUAN,
 *    bukan saat penyimpanan, sehingga baris yang sampai ke buku besar tetap
 *    utuh seperti sebelumnya.
 *
 * 2. Jejak persetujuan dicatat sistem. Kolom teks bebas `approved_by_name`
 *    sengaja TIDAK dihapus: isinya milik data lama yang diketik penginput,
 *    dan membuangnya menghilangkan catatan yang mungkin masih berguna. Yang
 *    berubah, form berhenti mengisinya, dan UI menandainya "diisi manual"
 *    supaya tidak tertukar dengan penyetuju hasil sistem.
 */
return new class extends Migration
{
    /** tabel => kolom yang boleh kosong selama masih draft */
    private const LONGGAR = [
        'pembayaran' => ['amount', 'category', 'source_account_code', 'debit_account_code'],
        'penerimaan' => ['amount', 'category', 'destination_account_code', 'credit_account_code'],
    ];

    private const STATUS_BARU = ['draft', 'submitted', 'posted', 'rejected', 'cancelled'];
    private const STATUS_LAMA = ['draft', 'posted', 'cancelled'];

    public function up(): void
    {
        foreach (array_keys(self::LONGGAR) as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->timestamp('submitted_at')->nullable();
                $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('rejected_at')->nullable();
                $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('reject_reason', 255)->nullable();
                $table->index(['status', 'submitted_at']);
            });

            foreach (self::LONGGAR[$t] as $kolom) {
                DB::statement("ALTER TABLE {$t} ALTER COLUMN {$kolom} DROP NOT NULL");
            }

            $this->setStatuses($t, self::STATUS_BARU);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::LONGGAR) as $t) {
            // Baris berstatus baru dikembalikan ke keadaan yang masih sah di
            // skema lama, kalau tidak CHECK-nya gagal. submitted paling dekat
            // ke draft (belum masuk buku), rejected pun belum pernah masuk.
            DB::table($t)->whereIn('status', ['submitted', 'rejected'])->update(['status' => 'draft']);
            $this->setStatuses($t, self::STATUS_LAMA);

            // Kolom yang dilonggarkan tidak bisa dikembalikan NOT NULL
            // selama masih ada draft tak lengkap; isi seadanya dulu.
            foreach (self::LONGGAR[$t] as $kolom) {
                DB::table($t)->whereNull($kolom)->update([$kolom => $kolom === 'amount' ? 0 : '']);
                DB::statement("ALTER TABLE {$t} ALTER COLUMN {$kolom} SET NOT NULL");
            }

            Schema::table($t, function (Blueprint $table) {
                $table->dropIndex(['status', 'submitted_at']);
                $table->dropConstrainedForeignId('submitted_by_user_id');
                $table->dropConstrainedForeignId('rejected_by_user_id');
                $table->dropColumn(['submitted_at', 'rejected_at', 'reject_reason']);
            });
        }
    }

    private function setStatuses(string $table, array $statuses): void
    {
        $name = "{$table}_status_check";
        $daftar = implode(', ', array_map(fn ($s) => "'" . $s . "'", $statuses));
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK (status IN ({$daftar}))");
    }
};
