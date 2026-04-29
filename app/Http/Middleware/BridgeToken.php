<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the print bridge service via a shared static token.
 * Header: X-Bridge-Token: <token-from-config>
 *
 * Simpler than Sanctum (no user account, no DB hit per request) and
 * appropriate for a single-tenant bridge running on the LAN.
 */
class BridgeToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('print.bridge_token');
        $provided = $request->header('X-Bridge-Token');

        if (empty($expected) || ! is_string($provided) || ! hash_equals($expected, $provided)) {
            abort(401, 'Invalid or missing bridge token.');
        }

        return $next($request);
    }
}
