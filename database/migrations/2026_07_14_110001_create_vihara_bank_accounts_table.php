<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vihara_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vihara_id')->constrained('viharas')->cascadeOnDelete();
            $table->string('bank_name', 64);              // "Bank BCA", "Bank Mandiri"
            $table->string('account_number', 32);
            $table->string('account_name');               // nama pemilik rekening
            // FK-ish ke accounts.code — dipakai untuk derive akun JE saat transaksi
            // memilih rekening ini (default 1130 Bank Donasi).
            $table->string('coa_account_code', 10)->default('1130');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['vihara_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vihara_bank_accounts');
    }
};
