<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

use App\Models\Client;
use App\Domain\Tenancy\TenantManager;
use App\Models\Tenant\LogSource;
use App\Jobs\PullLogsFromSSH;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduler to check active clients and schedule SSH log pull workers
Schedule::call(function () {
    $tenantManager = app(TenantManager::class);
    $clients = Client::where('status', 'active')->get();

    foreach ($clients as $client) {
        $tenantManager->switchToTenant($client);

        try {
            $sources = LogSource::where('type', 'ssh_pull')
                ->where('status', 'active')
                ->get();

            foreach ($sources as $source) {
                $lastPolled = $source->last_polled_at;
                $interval = $source->polling_interval_seconds ?? 300;

                if (!$lastPolled || $lastPolled->addSeconds($interval)->isPast()) {
                    // Dispatch job with client ID context
                    PullLogsFromSSH::dispatch($client->id, $source->id);
                }
            }
        } catch (\Exception $e) {
            Log::error("Failed to schedule log pull for client {$client->slug}: " . $e->getMessage());
        } finally {
            $tenantManager->resetToCentral();
        }
    }
})->everyMinute();
