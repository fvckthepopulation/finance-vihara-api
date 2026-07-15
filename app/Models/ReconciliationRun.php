<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code', 'vihara_id', 'bank_account_id', 'account_code', 'period_month',
    'period_start', 'period_end',
    'status', 'bank_opening_balance', 'bank_closing_balance', 'system_balance',
    'adjusted_balance', 'started_by', 'started_at', 'completed_by', 'completed_at',
    'notes',
])]
class ReconciliationRun extends Model
{
    protected $table = 'reconciliation_runs';

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'bank_opening_balance' => 'decimal:2',
            'bank_closing_balance' => 'decimal:2',
            'system_balance' => 'decimal:2',
            'adjusted_balance' => 'decimal:2',
        ];
    }

    public function vihara(): BelongsTo { return $this->belongsTo(Vihara::class); }
    public function bankAccount(): BelongsTo { return $this->belongsTo(ViharaBankAccount::class, 'bank_account_id'); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class, 'account_code', 'code'); }
    public function bankLines(): HasMany { return $this->hasMany(BankStatementLine::class); }
}
