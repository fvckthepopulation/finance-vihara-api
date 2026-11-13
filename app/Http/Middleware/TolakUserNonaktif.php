<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Akun yang dinonaktifkan ditolak pada permintaan BERIKUTNYA, bukan hanya saat
 * login (C-ADM-05).
 *
 * Tanpa ini, menonaktifkan user yang sedang login tidak berpengaruh apa-apa:
 * tokennya masih sah sampai kedaluwarsa, jadi orang yang baru saja dicabut
 * aksesnya tetap bisa bekerja sampai ia sendiri keluar.
 */
class TolakUserNonaktif
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->active) {
            $user->tokens()->delete();

            return response()->json([
                'message' => 'Akun ini nonaktif. Hubungi admin.',
            ], 403);
        }

        return $next($request);
    }
}
