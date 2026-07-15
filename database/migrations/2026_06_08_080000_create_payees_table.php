<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified payee ("Dibayarkan kepada") master for the Pembayaran form.
 *
 * Holds the recipients shown in the per-category LOV in the mock-up
 * (finance_8Jun2026.html → masterPenerima): landlords, utilities (PLN/PDAM),
 * the tax office (DJP), service providers, and goods suppliers — distinguished
 * by `type` (supplier | provider). `categories` scopes which Pembayaran
 * categories a payee appears under (null/empty = all).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payees', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->nullable()->unique();
            $table->string('name', 255);
            $table->enum('type', ['supplier', 'provider'])->default('provider');

            // Which Pembayaran categories this payee is offered for. NULL = all.
            $table->json('categories')->nullable();

            // Disbursement target (rekening tujuan)
            $table->string('bank_name', 60)->nullable();
            $table->string('bank_account_number', 40)->nullable();
            $table->string('bank_account_holder', 120)->nullable();
            $table->string('branch', 120)->nullable()->comment('cabang');

            $table->string('npwp', 30)->nullable();
            $table->string('contact_person', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();

            // Optional scoping to a single entity (NULL = available to all)
            $table->foreignId('entity_id')->nullable()->constrained('entities')->nullOnDelete();

            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('type');
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payees');
    }
};
