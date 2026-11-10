<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hirarki akses user. Menggantikan `users.vihara_id` (tunggal) dengan
 * kombinasi `scope_type` + pivot `user_vihara` (jamak):
 *
 *   global  -> semua yayasan, semua vihara (admin tertinggi)
 *   entity  -> seluruh vihara di bawah users.entity_id + data level yayasan
 *   vihara  -> HANYA vihara yang terdaftar di pivot user_vihara (1..n)
 *
 * Sebelumnya hanya ada dua kemungkinan: satu vihara (vihara_id terisi) atau
 * semua vihara (null). "Beberapa vihara" tidak bisa dinyatakan sama sekali.
 *
 * Backfill dari data lama:
 *   vihara_id NULL + role superadmin -> global
 *   vihara_id NULL lainnya           -> entity
 *   vihara_id terisi                 -> vihara + satu baris pivot
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_vihara', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('vihara_id')->constrained('viharas')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'vihara_id']);
            $table->index('vihara_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('scope_type', 16)->default('vihara')->after('entity_id')
                ->comment('global | entity | vihara');
            $table->index('scope_type');
        });

        // --- Backfill sebelum kolom lama dibuang ---

        $superadminIds = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'superadmin')
            ->pluck('model_has_roles.model_id')
            ->all();

        // User tanpa vihara_id = akses lintas vihara pada model lama.
        DB::table('users')->whereNull('vihara_id')->update(['scope_type' => 'entity']);

        if ($superadminIds !== []) {
            DB::table('users')->whereIn('id', $superadminIds)->update(['scope_type' => 'global']);
        }

        DB::table('users')->whereNotNull('vihara_id')->update(['scope_type' => 'vihara']);

        $scoped = DB::table('users')->whereNotNull('vihara_id')->get(['id', 'vihara_id']);
        $now = now();
        foreach ($scoped as $u) {
            DB::table('user_vihara')->insert([
                'user_id' => $u->id,
                'vihara_id' => $u->vihara_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vihara_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('vihara_id')->nullable()->after('entity_id')
                ->constrained('viharas')->nullOnDelete();
        });

        // Kembalikan vihara pertama tiap user (model lama hanya muat satu).
        $rows = DB::table('user_vihara')->orderBy('id')->get(['user_id', 'vihara_id']);
        $seen = [];
        foreach ($rows as $r) {
            if (isset($seen[$r->user_id])) {
                continue;
            }
            $seen[$r->user_id] = true;
            DB::table('users')->where('id', $r->user_id)->update(['vihara_id' => $r->vihara_id]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['scope_type']);
            $table->dropColumn('scope_type');
        });

        Schema::dropIfExists('user_vihara');
    }
};
