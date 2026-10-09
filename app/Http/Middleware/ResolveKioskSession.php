<?php

namespace App\Http\Middleware;

use App\Services\Sessions\KioskAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates participant kiosk requests by the bearer token in the URL and
 * exposes the resolved session as the "kioskSession" request attribute.
 */
class ResolveKioskSession
{
    public function __construct(private KioskAccess $access) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->access->resolve((string) $request->route('token'));

        abort_unless($session, 404);

        $session->forceFill(['kiosk_last_seen_at' => now()])->saveQuietly();

        $request->attributes->set('kioskSession', $session);
        $request->route()->forgetParameter('token');

        return $next($request);
    }
}
