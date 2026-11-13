<?php

use App\Models\Pembayaran;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FV-29 — master Kategori Biaya.
 *
 * Kategori pengeluaran selama ini konstanta PHP (Pembayaran::CATEGORIES), jadi
 * admin tidak bisa menambah atau menonaktifkan apa pun tanpa rilis baru.
 * Tabel ini memindahkannya ke data, dengan `code` yang PERSIS sama dengan
 * kunci konstanta lama supaya seluruh baris pembayaran yang sudah ada tetap
 * terhubung (kolom pembayaran.category menyimpan kode itu).
 *
 * Kolom `type` disediakan sejak awal, tapi yang diisi migrasi ini hanya
 * kategori pengeluaran. Kategori penerimaan punya makna tambahan (pembatasan
 * tp/dp yang menentukan klasifikasi laporan ISAK 35) dan tetap di konstanta
 * sampai ada issue yang membahasnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_categories', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16)->default('pengeluaran');
            $table->string('code', 48);
            $table->string('name');
            $table->text('description')->nullable();
            // Akun beban bawaan kategori — dipakai sebagai cadangan kalau Nama
            // Pengeluaran yang dipilih belum punya akunnya sendiri.
            $table->string('default_debit_code', 16)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'code']);
            $table->index(['type', 'active']);
        });

        DB::statement(
            "ALTER TABLE transaction_categories
             ADD CONSTRAINT transaction_categories_type_check
             CHECK (type IN ('pengeluaran', 'penerimaan'))"
        );

        $now = now();
        $rows = [];
        foreach (Pembayaran::CATEGORIES as $code => $meta) {
            $rows[] = [
                'type' => 'pengeluaran',
                'code' => $code,
                'name' => $meta['label'],
                'description' => null,
                'default_debit_code' => $meta['default_debit'],
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('transaction_categories')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_categories');
    }
};
