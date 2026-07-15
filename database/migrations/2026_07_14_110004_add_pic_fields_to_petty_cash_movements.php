<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kas kecil per PIC per vihara. Kolom nullable supaya movement legacy
     * (single imprest, category-based) tetap valid.
     */
    public function up(): void
    {
        Schema::table('petty_cash_movements', function (Blueprint $table) {
            $table->foreignId('vihara_id')->nullable()
                ->constrained('viharas')->nullOnDelete();
            $table->foreignId('pic_id')->nullable()
                ->constrained('petty_cash_pics')->restrictOnDelete();
            $table->enum('subtype', ['topup', 'usage'])->nullable();
            // Sumber dana topup; null = dari kas tunai (1110).
            $table->foreignId('source_bank_account_id')->nullable()
                ->constrained('vihara_bank_accounts')->nullOnDelete();
            $table->string('reference', 100)->nullable(); // no bukti (BKK-001 dsb)

            $table->index(['vihara_id', 'pic_id']);
        });
    }

    public function down(): void
    {
        Schema::table('petty_cash_movements', function (Blueprint $table) {
            $table->dropColumn('reference');
            $table->dropConstrainedForeignId('source_bank_account_id');
            $table->dropColumn('subtype');
            $table->dropConstrainedForeignId('pic_id');
            $table->dropConstrainedForeignId('vihara_id');
        });
    }
};
