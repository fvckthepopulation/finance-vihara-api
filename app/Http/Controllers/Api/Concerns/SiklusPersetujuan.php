<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Services\ViharaScopeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Perpindahan keadaan draft → diajukan → disetujui / ditolak.
 *
 * Dipakai bersama Pembayaran dan Penerimaan karena keduanya bentuknya kembar;
 * dua salinan logika seperti ini cepat menyimpang satu sama lain. Yang TIDAK
 * ada di sini adalah pembuatan jurnalnya, karena susunan debit-kreditnya
 * memang berbeda per jenis transaksi — itu tetap di controller masing-masing.
 */
trait SiklusPersetujuan
{
    /**
     * Siapa yang boleh melanjutkan sebuah draft: PEMBUATNYA, dan superadmin.
     *
     * Jangkauan vihara saja tidak cukup. Satu vihara bisa punya beberapa
     * pencatat, dan draft adalah pekerjaan setengah jadi milik satu orang —
     * bukan antrean bersama. Tanpa penjagaan ini, rekan satu vihara bisa
     * mengubah atau menghapus draft orang lain, dan pemiliknya tidak pernah
     * tahu apa yang berubah (FV-44 poin 5).
     *
     * Baris impor (mis. donasi KalyanaMitta) tidak punya pembuat; yang itu
     * hanya superadmin yang boleh menyentuhnya.
     */
    protected function guardPemilikDraft(Request $request, Model $row, string $label): void
    {
        $user = $request->user();
        if ($user->hasRole('superadmin')) {
            return;
        }
        if ($row->created_by_user_id !== null && (int) $row->created_by_user_id === (int) $user->id) {
            return;
        }

        abort(403, "Hanya pembuat draft {$label} ini yang bisa mengubah, menghapus, atau mengajukannya.");
    }

    /**
     * Ajukan draft ke antrean persetujuan.
     *
     * Kelengkapan ditegakkan DI SINI, bukan saat penyimpanan: draft memang
     * boleh setengah jadi, tapi yang masuk antrean orang lain harus utuh.
     */
    protected function ajukan(Request $request, Model $row, string $label, array $wajib): JsonResponse
    {
        ViharaScopeService::guardRecord($request, $row->vihara_id);
        $this->guardPemilikDraft($request, $row, $label);

        // Yang DITOLAK juga boleh diajukan lagi — itu memang gunanya
        // penolakan: dikembalikan untuk diperbaiki, bukan dibuang.
        if (! in_array($row->status, ['draft', 'rejected'], true)) {
            return response()->json([
                'message' => "Hanya {$label} berstatus draft atau ditolak yang bisa diajukan.",
            ], 422);
        }

        $kurang = [];
        foreach ($wajib as $kolom => $namaTampil) {
            if ($row->{$kolom} === null || $row->{$kolom} === '' || $row->{$kolom} == 0) {
                $kurang[$namaTampil] = ["{$namaTampil} wajib diisi sebelum diajukan."];
            }
        }
        if ($kurang) {
            return response()->json([
                'message' => 'Lengkapi dulu: ' . implode(', ', array_keys($kurang)) . '.',
                'errors' => $kurang,
            ], 422);
        }

        $row->forceFill([
            'status' => 'submitted',
            'submitted_at' => now(),
            'submitted_by_user_id' => $request->user()->id,
            // Pengajuan ulang sesudah ditolak membersihkan jejak penolakan,
            // supaya approver tidak melihat alasan lama yang sudah tidak
            // berlaku. Siapa yang menolak tetap tercatat di riwayat jurnal
            // audit bila nanti diperlukan.
            'rejected_at' => null,
            'rejected_by_user_id' => null,
            'reject_reason' => null,
        ])->save();

        return response()->json($row->fresh());
    }

    /** Tolak pengajuan, dengan alasan yang wajib diisi. */
    protected function tolak(Request $request, Model $row, string $label): JsonResponse
    {
        ViharaScopeService::guardRecord($request, $row->vihara_id);

        if ($row->status !== 'submitted') {
            return response()->json([
                'message' => "Hanya {$label} yang sedang menunggu persetujuan yang bisa ditolak.",
            ], 422);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'reason.required' => 'Alasan penolakan wajib diisi.',
            'reason.min' => 'Alasan penolakan terlalu singkat.',
        ]);

        // Dikembalikan ke pembuatnya sebagai ditolak, BUKAN dihapus: pengaju
        // perlu tahu apa yang salah, dan bisa memperbaiki lalu mengajukan lagi.
        $row->forceFill([
            'status' => 'rejected',
            'rejected_at' => now(),
            'rejected_by_user_id' => $request->user()->id,
            'reject_reason' => $data['reason'],
        ])->save();

        return response()->json($row->fresh());
    }
}
