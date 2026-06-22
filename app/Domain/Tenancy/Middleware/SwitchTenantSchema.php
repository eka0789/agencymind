<?php

namespace App\Domain\Tenancy\Middleware;

use App\Models\Client;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Support\Facades\Log;

class SwitchTenantSchema
{
    protected $clientId;

    public function __construct($clientId)
    {
        $this->clientId = $clientId;
    }

    /**
     * Process the queued job.
     */
    public function handle($job, $next)
    {
        $client = Client::find($this->clientId);

        if ($client) {
            $tenantManager = app(TenantManager::class);
            $tenantManager->switchToTenant($client);
            Log::debug("Job switched to tenant schema: {$client->schema_name}");
        }

        try {
            return $next($job);
        } finally {
            $tenantManager = app(TenantManager::class);
            $tenantManager->resetToCentral();
            Log::debug("Job reset to central schema");
        }
    }
}
