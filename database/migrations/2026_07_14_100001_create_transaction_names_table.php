<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master "Nama Penerimaan / Nama Pengeluaran" — lookup untuk dropdown form
 * input transaksi (per mockup). Satu baris = satu nama transaksi baku di
 * bawah sebuah kategori dana/biaya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_names', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['penerimaan', 'pengeluaran']);
            $table->string('category');
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'name']);
            $table->index(['type', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_names');
    }
};
