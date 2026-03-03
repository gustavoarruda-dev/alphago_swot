<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateInternalApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredKeys = array_values(array_filter(array_unique([
            trim((string) config('services.gateway.api_key', '')),
            trim((string) config('services.swot.api_key', '')),
        ])));

        $headerApiKey = trim((string) $request->header('X-API-KEY', ''));
        $bearerToken = trim((string) ($request->bearerToken() ?? ''));

        if (! empty($configuredKeys)) {
            foreach ($configuredKeys as $configuredKey) {
                if (
                    ($headerApiKey !== '' && hash_equals($configuredKey, $headerApiKey)) ||
                    ($bearerToken !== '' && hash_equals($configuredKey, $bearerToken))
                ) {
                    return $next($request);
                }
            }
        }

        throw new AuthenticationException('Unauthenticated.');
    }
}
