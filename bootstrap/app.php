<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Alias Spatie: 'permission' menerima beberapa izin dengan arti ATAU
        // ("a|b"), yang tidak bisa dilakukan middleware 'can' bawaan Laravel.
        // Dipakai antrean persetujuan — lihat routes/api.php.
        // Aplikasi ini API-only: tidak ada route bernama 'login'. Tanpa ini,
        // permintaan TANPA header Accept: application/json yang gagal auth
        // membalas 500 "Route [login] not defined" — bukan 401. Terlihat saat
        // menguji akun nonaktif dengan curl polos, dan sebelumnya dari
        // <img src=> yang tidak membawa Bearer token.
        $middleware->redirectGuestsTo(fn (Request $request) => null);

        $middleware->alias([
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            // Akun nonaktif ditolak di permintaan berikutnya, bukan hanya saat
            // login (C-ADM-05).
            'user.aktif' => \App\Http\Middleware\TolakUserNonaktif::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // API requests must always get JSON 401 on auth failure. Without this,
        // Laravel's default redirectTo() tries to resolve route('login'), which
        // does not exist in this API-only app, and the missing-route exception
        // surfaces as a 500. (Triggered when, e.g., <img src=> hits a guarded
        // endpoint and the browser can't attach a Bearer token.)
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    })->create();
