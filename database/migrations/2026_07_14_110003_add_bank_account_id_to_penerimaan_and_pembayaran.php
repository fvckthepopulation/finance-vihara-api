<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penerimaan', function (Blueprint $table) {
            $table->foreignId('bank_account_id')->nullable()
                ->constrained('vihara_bank_accounts')->nullOnDelete();
        });
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->foreignId('bank_account_id')->nullable()
                ->constrained('vihara_bank_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('penerimaan', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_id');
        });
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_id');
        });
    }
};
