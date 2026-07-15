<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_pics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vihara_id')->constrained('viharas')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['vihara_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_pics');
    }
};
