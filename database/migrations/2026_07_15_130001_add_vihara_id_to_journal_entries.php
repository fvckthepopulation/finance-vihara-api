<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dimensi vihara pada journal_entries — dipakai Laporan ISAK 35 per vihara.
 * Null = jurnal level yayasan (mis. manual JE lintas vihara).
 *
 * Backfill: ambil vihara_id dari dokumen sumber via reference_type/
 * reference_id (penerimaan & penerimaan_reklas → penerimaan; pembayaran →
 * pembayaran; petty_cash → petty_cash_movements).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('vihara_id')
                ->nullable()
                ->after('entity_id')
                ->constrained('viharas')
                ->nullOnDelete();
            $table->index('vihara_id');
        });

        $sources = [
            'penerimaan' => ['penerimaan', 'penerimaan_reklas'],
            'pembayaran' => ['pembayaran'],
            'petty_cash_movements' => ['petty_cash', 'petty_cash_movement'],
        ];

        foreach ($sources as $table => $referenceTypes) {
            DB::table('journal_entries')
                ->whereIn('reference_type', $referenceTypes)
                ->whereNotNull('reference_id')
                ->whereNull('vihara_id')
                ->update([
                    'vihara_id' => DB::raw(
                        "(select vihara_id from {$table} where {$table}.id = journal_entries.reference_id)",
                    ),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vihara_id');
        });
    }
};
