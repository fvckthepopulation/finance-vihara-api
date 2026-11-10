<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hirarki tenant: Yayasan (entities) -> Vihara (viharas) -> transaksi.
 *
 * Sebelum ini `viharas` tidak punya relasi apa pun ke `entities`, sehingga
 * "satu yayasan menaungi beberapa vihara" hanya asumsi, bukan model data.
 * Kolom ini yang membuat scoping dua lapis (yayasan -> vihara) mungkin.
 *
 * Backfill: semua vihara lama diikat ke entity pertama (YDI pada data seed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viharas', function (Blueprint $table) {
            $table->foreignId('entity_id')->nullable()->after('id')
                ->constrained('entities')->nullOnDelete();
            $table->index(['entity_id', 'active']);
        });

        // Vihara lama tidak punya yayasan — ikat ke entity paling awal.
        $firstEntityId = DB::table('entities')->orderBy('id')->value('id');
        if ($firstEntityId !== null) {
            DB::table('viharas')->whereNull('entity_id')->update(['entity_id' => $firstEntityId]);
        }
    }

    public function down(): void
    {
        Schema::table('viharas', function (Blueprint $table) {
            $table->dropIndex(['entity_id', 'active']);
            $table->dropConstrainedForeignId('entity_id');
        });
    }
};
