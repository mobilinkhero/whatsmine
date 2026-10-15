<?php

namespace App\Http\Middleware;

use App\Services\License\LicenseManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nulled EnsureLicensed middleware. 
 * Bypasses all license verification and allows requests to pass through.
 */
class EnsureLicensed
{
    public function __construct(private LicenseManager $license) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Bypass license checks entirely and pass the request through
        return $next($request);
    }
}
