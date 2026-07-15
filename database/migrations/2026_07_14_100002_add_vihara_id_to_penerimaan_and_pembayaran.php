<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-vihara scoping untuk transaksi: penerimaan + pembayaran menempel ke
 * satu vihara. Baris lama di-backfill ke vihara id 1 (VVG) — data sample
 * Phase 1 semuanya milik VVG.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penerimaan', function (Blueprint $table) {
            $table->foreignId('vihara_id')->nullable()->after('entity_id')
                ->constrained('viharas')->nullOnDelete();
            $table->index(['vihara_id', 'date']);
        });
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->foreignId('vihara_id')->nullable()->after('entity_id')
                ->constrained('viharas')->nullOnDelete();
            $table->index(['vihara_id', 'date']);
        });

        // Backfill hanya kalau vihara 1 ada (fresh migrate: tabel masih kosong).
        if (DB::table('viharas')->where('id', 1)->exists()) {
            DB::table('penerimaan')->whereNull('vihara_id')->update(['vihara_id' => 1]);
            DB::table('pembayaran')->whereNull('vihara_id')->update(['vihara_id' => 1]);
        }
    }

    public function down(): void
    {
        Schema::table('penerimaan', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vihara_id');
        });
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vihara_id');
        });
    }
};
