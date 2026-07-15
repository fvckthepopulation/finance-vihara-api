<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field form input per mockup Phase 2:
 * - penerimaan: `name` (Nama Penerimaan, dari master transaction_names) +
 *   `received_by` (Diterima Oleh).
 * - pembayaran: `name` (Nama Pengeluaran) + `approved_by_name`
 *   (Disetujui Oleh — free text, bukan FK user).
 * Semua nullable supaya alur create lama tetap jalan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penerimaan', function (Blueprint $table) {
            $table->string('name')->nullable()->after('category');
            $table->string('received_by')->nullable()->after('source_name');
        });
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->string('name')->nullable()->after('category');
            $table->string('approved_by_name')->nullable()->after('payee_name');
        });
    }

    public function down(): void
    {
        Schema::table('penerimaan', function (Blueprint $table) {
            $table->dropColumn(['name', 'received_by']);
        });
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->dropColumn(['name', 'approved_by_name']);
        });
    }
};
