<?php

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Middleware\SwitchTenantSchema;

trait TenantAwareJob
{
    public $clientId;

    /**
     * Get the middleware the job should pass through.
     *
     * @return array
     */
    public function middleware(): array
    {
        return [new SwitchTenantSchema($this->clientId)];
    }
}
