<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C-ADM-05 — akun bisa dinonaktifkan.
 *
 * Satu-satunya cara mencabut akses seseorang selama ini adalah menghapus
 * akunnya, dan itu memutus jejak siapa yang mencatat transaksi lama. Kolom
 * `active` memisahkan "tidak boleh masuk lagi" dari "tidak pernah ada".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('active')->default(true)->after('scope_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('active');
        });
    }
};
