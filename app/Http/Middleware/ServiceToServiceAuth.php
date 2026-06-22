<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ServiceToServiceAuth
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $expectedToken = config('services.discord.api_token');

        if (empty($expectedToken) || $token !== $expectedToken) {
            return response()->json([
                'message' => 'Unauthorized service access'
            ], 401);
        }

        return $next($request);
    }
}
