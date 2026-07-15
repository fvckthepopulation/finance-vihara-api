<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('entities')->restrictOnDelete();
            $table->string('cash_account_code', 10);          // 102-XX (kas kecil)
            $table->enum('type', ['refill', 'expense']);
            $table->date('movement_date');
            $table->decimal('amount', 18, 2);
            $table->string('counterpart_account_code', 10);   // refill: source bank/kas besar; expense: expense account
            $table->string('recipient')->nullable();
            $table->string('category', 64)->nullable();        // ATK, konsumsi, transport, etc (free text)
            $table->text('description')->nullable();
            $table->string('attachment_path')->nullable();
            $table->enum('status', ['draft', 'posted'])->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->foreign('cash_account_code')->references('code')->on('accounts')->restrictOnDelete();
            $table->foreign('counterpart_account_code')->references('code')->on('accounts')->restrictOnDelete();
            $table->index(['entity_id', 'movement_date']);
            $table->index('cash_account_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_movements');
    }
};
