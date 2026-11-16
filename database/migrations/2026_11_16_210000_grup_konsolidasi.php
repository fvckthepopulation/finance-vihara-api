<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FV-66 — Grup Konsolidasi.
 *
 * Konsolidasi adalah lapisan laporan di atas pembukuan tiap yayasan yang
 * tetap terpisah: grup menentukan yayasan mana yang dilaporkan bersama dan
 * sejak kapan. Menggabung/memisah yayasan cukup lewat keanggotaan, tanpa
 * memindahkan vihara atau transaksi.
 *
 * Ikut ditambahkan akun transaksi antar yayasan (P-08) yang dieliminasi di
 * laporan grup: 5400 (pemberi) ↔ 4410/4420 (penerima), 1320 ↔ 2320.
 */
return new class extends Migration
{
    private const AKUN = [
        // code, name, type, parent, is_header
        ['4400', 'PENERIMAAN DARI YAYASAN AFILIASI', 'revenue', null, true],
        ['4410', 'Penerimaan dari Yayasan Afiliasi — Tanpa Pembatasan', 'revenue', '4400', false],
        ['4420', 'Penerimaan dari Yayasan Afiliasi — Dengan Pembatasan', 'revenue', '4400', false],
        ['5400', 'Penyaluran ke Yayasan Afiliasi', 'expense', '5000', false],
        ['1320', 'Piutang Antar Yayasan', 'asset', '1300', false],
        ['2320', 'Utang Antar Yayasan', 'liability', '2100', false],
    ];

    public function up(): void
    {
        Schema::create('konsolidasi_groups', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150)->unique();
            $t->string('description', 500)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('konsolidasi_group_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('konsolidasi_group_id')->constrained('konsolidasi_groups')->cascadeOnDelete();
            $t->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->timestamps();
            $t->index(['entity_id', 'start_date']);
        });

        // Hak akses konsolidasi per grup (selain user global yang melihat semua).
        Schema::create('konsolidasi_group_user', function (Blueprint $t) {
            $t->foreignId('konsolidasi_group_id')->constrained('konsolidasi_groups')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->primary(['konsolidasi_group_id', 'user_id']);
        });

        foreach (self::AKUN as [$code, $name, $type, $parent, $header]) {
            if (DB::table('accounts')->where('code', $code)->exists()) {
                continue;
            }
            DB::table('accounts')->insert([
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'parent_code' => $parent,
                'entity_id' => null,
                'is_header' => $header,
                'active' => true,
                'is_system' => true,
                'note' => 'Transaksi antar yayasan (P-08, FV-66) — dieliminasi di laporan Grup Konsolidasi.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('konsolidasi_group_user');
        Schema::dropIfExists('konsolidasi_group_members');
        Schema::dropIfExists('konsolidasi_groups');
        // Akun dibiarkan: bisa sudah dipakai jurnal.
    }
};
