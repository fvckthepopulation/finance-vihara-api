<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auth machine-to-machine untuk webhook KM Finance:
 * `Authorization: Bearer <KM_WEBHOOK_TOKEN>` (shared secret, env).
 * Dipasang di route /api/webhooks/km/* — DI LUAR auth:sanctum.
 */
class VerifyKmWebhookToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.km.webhook_token');
        $given = (string) $request->bearerToken();

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
