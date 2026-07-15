<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anggaran per vihara per tahun. Satu row = target satu (type, category)
 * — name null = target level kategori; name terisi = target per Nama
 * Pengeluaran (detail).
 *
 * NB Postgres memperlakukan NULL sebagai distinct pada unique index, jadi
 * level kategori (name IS NULL) dijaga oleh partial unique index terpisah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vihara_id')->constrained('viharas')->cascadeOnDelete();
            $table->integer('year');
            $table->string('type', 16); // penerimaan | pengeluaran
            $table->string('category', 64);
            $table->string('name')->nullable();
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->unique(['vihara_id', 'year', 'type', 'category', 'name']);
            $table->index(['vihara_id', 'year']);
        });

        DB::statement(
            'create unique index budgets_category_level_unique on budgets (vihara_id, year, type, category) where name is null',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
