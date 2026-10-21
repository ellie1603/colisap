<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content-Security-Policy and Permissions-Policy (the hosting proxy already sends HSTS, X-Frame-Options,
 * nosniff and Referrer-Policy). The policy allows only this site's own scripts, styles, fonts and images;
 * 'unsafe-inline'/'unsafe-eval' are required by Livewire and Alpine, which Filament is built on.
 */
class SecurityHeaders
{
    private const CONTENT_SECURITY_POLICY = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
        "style-src 'self' 'unsafe-inline'",
        "img-src 'self' data: blob:",
        "font-src 'self' data:",
        "connect-src 'self'",
        "worker-src 'self' blob:",
        "frame-ancestors 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
    ];

    private const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Permissions-Policy', self::PERMISSIONS_POLICY, false);

        // Pages only: PDFs and downloads open in the browser's own viewer.
        if (str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $policy = self::CONTENT_SECURITY_POLICY;

            if ($request->isSecure()) {
                $policy[] = 'upgrade-insecure-requests';
            }

            $response->headers->set('Content-Security-Policy', implode('; ', $policy), false);
        }

        return $response;
    }
}
