<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\Client;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantSchema
{
    protected $tenantManager;

    public function __construct(TenantManager $tenantManager)
    {
        $this->tenantManager = $tenantManager;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $clientSlug = $request->route('client') ?: $request->header('X-Tenant-Client');

        if ($clientSlug) {
            if ($clientSlug instanceof Client) {
                $client = $clientSlug;
            } else {
                $client = Client::where('slug', $clientSlug)->first();
            }

            if ($client) {
                // Switch schema
                $this->tenantManager->switchToTenant($client);
                
                // Store client in request attributes
                $request->attributes->set('client', $client);
            }
        }

        $response = $next($request);

        // Reset to central schema after request is complete
        $this->tenantManager->resetToCentral();

        return $response;
    }
}
