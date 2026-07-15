<?php

namespace App\Models\Concerns;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Secara default Eloquent men-serialize datetime ke UTC ("...Z"), padahal kolom
 * disimpan dalam jam aplikasi (Asia/Jakarta) lewat now(). Frontend menampilkan
 * string itu apa adanya (mis. posted_at.replace("T"," ").slice(0,16)), sehingga
 * jam yang tampil jadi mundur 7 jam.
 *
 * Trait ini meng-override serializeDate agar JSON memuat offset timezone
 * aplikasi (mis. 2026-06-18T09:56:47+07:00) — instant-nya sama, tapi jam yang
 * tampil benar jam Jakarta.
 */
trait SerializesDatesInAppTimezone
{
    protected function serializeDate(DateTimeInterface $date): string
    {
        return Carbon::instance($date)->toIso8601String();
    }
}
