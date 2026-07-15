<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `entity_id` to accounts so the rekening (bank) accounts can be grouped
 * by owning entity in the Pembayaran form's "sumber dana" dropdown.
 *
 * Nullable — only bank accounts (104-XX) carry an entity in practice;
 * shared expense/revenue accounts remain entity-less.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('entity_id')->nullable()->after('parent_code')
                ->constrained('entities')->nullOnDelete();
            $table->index('entity_id');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign(['entity_id']);
            $table->dropIndex(['entity_id']);
            $table->dropColumn('entity_id');
        });
    }
};
