<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FV-53 — jurnal manual ikut siklus persetujuan.
 *
 * Jurnal manual selalu lahir sebagai draft dan tidak pernah punya jalan
 * keluar: tidak ada tombol maupun route untuk mengajukan atau memposting,
 * sementara laporan hanya membaca jurnal posted. Akibatnya saldo awal,
 * koreksi, dan penyesuaian tidak pernah berpengaruh ke mana pun.
 *
 * Kolomnya menyamai pembayaran/penerimaan supaya satu siklus yang sama bisa
 * dipakai ketiganya: submitted → posted / rejected, lengkap dengan jejak
 * siapa dan kapan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reject_reason', 255)->nullable();
        });

        $this->setStatuses(['draft', 'submitted', 'posted', 'rejected', 'cancelled']);
    }

    public function down(): void
    {
        DB::table('journal_entries')->whereIn('status', ['submitted', 'rejected'])->update(['status' => 'draft']);
        $this->setStatuses(['draft', 'posted', 'cancelled']);

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by_user_id');
            $table->dropConstrainedForeignId('rejected_by_user_id');
            $table->dropColumn(['submitted_at', 'rejected_at', 'reject_reason']);
        });
    }

    private function setStatuses(array $statuses): void
    {
        $daftar = implode(', ', array_map(fn ($s) => "'".$s."'", $statuses));
        DB::statement('ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_status_check');
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_status_check CHECK (status IN ({$daftar}))");
    }
};
