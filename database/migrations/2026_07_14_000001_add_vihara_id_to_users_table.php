<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenant scoping: user dengan vihara_id hanya melihat data vihara
 * tersebut; vihara_id NULL = akses semua vihara (mis. admin yayasan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('vihara_id')->nullable()->after('entity_id')
                ->constrained('viharas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vihara_id');
        });
    }
};
