<?php

namespace App\Observers;

use App\Models\Client;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Support\Str;

class ClientObserver
{
    protected $tenantManager;

    public function __construct(TenantManager $tenantManager)
    {
        $this->tenantManager = $tenantManager;
    }

    /**
     * Handle the Client "creating" event.
     */
    public function creating(Client $client): void
    {
        if (empty($client->schema_name)) {
            // Generate schema name from name if not provided
            $client->schema_name = 'tenant_' . str_replace('-', '_', Str::slug($client->name));
        }
    }

    /**
     * Handle the Client "created" event.
     */
    public function created(Client $client): void
    {
        $this->tenantManager->createSchemaForClient($client);
    }
}
