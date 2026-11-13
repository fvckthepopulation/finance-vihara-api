<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'entity_id',
    'code', 'name', 'address',
    'contact_pic', 'contact_phone', 'contact_email',
    'bank_name', 'bank_account_number', 'bank_account_holder',
    'npwp', 'active',
    'verified_at', 'verified_by_user_id', 'is_umum',])]
class Vihara extends Model
{
    use SerializesDatesInAppTimezone;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'is_umum' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /** Yayasan yang menaungi vihara ini. */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /**
     * User yang di-assign eksplisit ke vihara ini (scope_type = vihara).
     * User scope `entity`/`global` tidak muncul di sini — aksesnya diturunkan
     * dari yayasan, bukan dari pivot.
     */
    public function users(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_vihara')->withTimestamps();
    }

    /** Rekening bank milik vihara (master, dipakai transaksi & kas-bank). */
    /**
     * Hapus unit Umum — hanya dipakai saat yayasannya sendiri dihapus.
     * Jalur biasa (ViharaController::destroy) memang menolaknya (P-01).
     */
    public function forceDeleteUnitUmum(): void
    {
        if ($this->is_umum) {
            $this->delete();
        }
    }

    /**
     * Rekening yang boleh dipakai vihara ini (FV-36). Rekening milik yayasan;
     * pivot kosong berarti seluruh vihara yayasan itu boleh memakainya.
     */
    public function usableBankAccounts()
    {
        return ViharaBankAccount::query()->usableByVihara($this->id);
    }

    /** PIC pemegang kas kecil vihara. */
    public function pettyCashPics(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PettyCashPic::class);
    }
}
