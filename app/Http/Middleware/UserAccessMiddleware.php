<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\Client;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

class UserAccessMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $clientSlug = $request->route('client') ?: $request->header('X-Tenant-Client');

        if ($clientSlug) {
            $client = Client::where('slug', $clientSlug)->first();

            if (!$client) {
                return response()->json(['error' => 'Client not found'], 404);
            }

            $user = Auth::user();
            if ($user && !$user->hasAccessToClient($client)) {
                return response()->json(['error' => 'Forbidden: You do not have access to this client'], 403);
            }
        }

        return $next($request);
    }
}
