<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vihara = penerima donasi (recipient). Master record dengan rekening bank
 * tujuan transfer settlement. Vihara harus verified sebelum boleh masuk
 * batch settlement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viharas', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique()->comment('V-001 dst');
            $table->string('name');
            $table->string('address')->nullable();

            // Kontak PIC
            $table->string('contact_pic')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('contact_email')->nullable();

            // Rekening bank untuk settlement
            $table->string('bank_name', 64);
            $table->string('bank_account_number', 32);
            $table->string('bank_account_holder');

            $table->string('npwp', 32)->nullable();

            $table->boolean('active')->default(true);

            // Verifikasi sebelum eligible untuk settlement
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['active', 'verified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viharas');
    }
};
