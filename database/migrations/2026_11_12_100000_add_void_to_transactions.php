<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pembatalan transaksi yang sudah terposting (FV-14).
 *
 * Keadaan sebelumnya: DELETE hanya menerima baris berstatus draft, sedangkan
 * UI selalu memposting begitu menyimpan. Jadi transaksi yang salah ketik
 * tidak bisa dihapus MAUPUN dibatalkan oleh siapa pun, termasuk superadmin —
 * satu-satunya koreksi adalah menulis jurnal manual. Yang hilang bukan
 * izinnya, melainkan mekanismenya.
 *
 * Yang ditambahkan di sini hanya jejaknya. Pembalikannya sendiri berupa
 * JURNAL PEMBALIK sungguhan (lihat App\Services\VoidService), bukan
 * penghapusan baris: data asli tetap utuh dan bisa diaudit.
 *
 * Catatan akuntansi yang penting: karena pembalikannya jurnal nyata, seluruh
 * laporan berbasis buku besar (ISAK 35, konsolidasi, kas & bank) otomatis
 * netral — asli dan pembaliknya saling meniadakan. Yang perlu dibantu adalah
 * laporan berbasis tabel transaksi (ringkasan dashboard, daftar transaksi),
 * dan itu ditangani dengan memindahkan status ke 'cancelled' supaya baris
 * batal tidak ikut dijumlahkan dua kali.
 */
return new class extends Migration
{
    private const TABLES = ['pembayaran', 'penerimaan', 'journal_entries', 'petty_cash_movements'];

    /** Status yang diizinkan sesudah migrasi ini. */
    private const STATUSES = [
        'pembayaran' => ['draft', 'posted', 'cancelled'],
        'penerimaan' => ['draft', 'posted', 'cancelled'],
        'journal_entries' => ['draft', 'posted', 'cancelled'],
        'petty_cash_movements' => ['draft', 'posted', 'cancelled'],
    ];

    private const STATUSES_LAMA = [
        'pembayaran' => ['draft', 'posted', 'cancelled'],
        'penerimaan' => ['draft', 'posted', 'cancelled'],
        'journal_entries' => ['draft', 'posted'],
        'petty_cash_movements' => ['draft', 'posted'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $t) {
            Schema::table($t, function (Blueprint $table) use ($t) {
                $table->timestamp('voided_at')->nullable();
                $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('void_reason', 255)->nullable();
                // Jurnal pembalik yang dihasilkan. Pada journal_entries ini
                // menunjuk ke tabelnya sendiri — wajar, pembalik sebuah
                // jurnal juga sebuah jurnal.
                $table->foreignId('void_journal_entry_id')->nullable()
                    ->constrained('journal_entries')->nullOnDelete();
                $table->index(['voided_at']);
            });

            $this->setStatuses($t, self::STATUSES[$t]);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $t) {
            // Baris yang terlanjur dibatalkan harus dikembalikan ke status
            // yang masih sah di skema lama, kalau tidak CHECK-nya gagal.
            if (! in_array('cancelled', self::STATUSES_LAMA[$t], true)) {
                DB::table($t)->where('status', 'cancelled')->update(['status' => 'posted']);
            }
            $this->setStatuses($t, self::STATUSES_LAMA[$t]);

            Schema::table($t, function (Blueprint $table) {
                $table->dropIndex(['voided_at']);
                $table->dropConstrainedForeignId('void_journal_entry_id');
                $table->dropConstrainedForeignId('voided_by_user_id');
                $table->dropColumn(['voided_at', 'void_reason']);
            });
        }
    }

    /**
     * Laravel membuat enum sebagai varchar + CHECK, jadi menambah nilai
     * berarti mengganti constraint-nya. Dikerjakan lewat SQL mentah karena
     * tidak ada API Schema untuk ini.
     */
    private function setStatuses(string $table, array $statuses): void
    {
        $name = "{$table}_status_check";
        $daftar = implode(', ', array_map(fn ($s) => "'" . $s . "'", $statuses));

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK (status IN ({$daftar}))");
    }
};
