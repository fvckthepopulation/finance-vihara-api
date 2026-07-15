<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_workflows', function (Blueprint $table) {
            $table->id();
            $table->string('resource_type', 64)->unique(); // 'ap_invoice', 'ar_invoice', 'ap_payment', 'ar_payment', 'petty_cash'
            $table->string('name');
            // steps JSON: [{ "order": 1, "name": "Input", "required_permission": "ap.invoice.create" }, ...]
            $table->json("steps");
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_workflows');
    }
};
