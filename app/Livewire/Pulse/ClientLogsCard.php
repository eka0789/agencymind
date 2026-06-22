<?php

namespace App\Livewire\Pulse;

use App\Models\Client;
use App\Models\Tenant\LogEntry;
use App\Domain\Tenancy\TenantManager;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;
use Illuminate\Support\Facades\Schema;

#[Lazy]
class ClientLogsCard extends Card
{
    /**
     * Render the component.
     */
    public function render()
    {
        [$clientStats, $time, $runAt] = $this->remember(function () {
            $tenantManager = app(TenantManager::class);
            $clients = Client::all();
            $interval = $this->periodAsInterval();

            $stats = [];
            foreach ($clients as $client) {
                try {
                    // Switch to tenant connection schema
                    $tenantManager->switchToTenant($client);

                    // Check if table exists in dynamic schema
                    if (Schema::hasTable('log_entries')) {
                        $totalLogs = LogEntry::where('timestamp', '>=', now()->sub($interval))->count();
                        
                        $errorLogs = LogEntry::where('timestamp', '>=', now()->sub($interval))
                            ->whereIn('level', ['error', 'critical', 'alert', 'emergency', 'fail'])
                            ->count();
                            
                        $warningLogs = LogEntry::where('timestamp', '>=', now()->sub($interval))
                            ->where('level', 'warning')
                            ->count();

                        $lastLog = LogEntry::orderBy('timestamp', 'desc')->first();
                        $lastIngestedAt = $lastLog ? $lastLog->timestamp->toDateTimeString() : 'N/A';
                    } else {
                        $totalLogs = 0;
                        $errorLogs = 0;
                        $warningLogs = 0;
                        $lastIngestedAt = 'N/A';
                    }

                    $stats[] = [
                        'name' => $client->name,
                        'slug' => $client->slug,
                        'status' => $client->status,
                        'total_logs' => $totalLogs,
                        'error_logs' => $errorLogs,
                        'warning_logs' => $warningLogs,
                        'last_ingested_at' => $lastIngestedAt,
                    ];
                } catch (\Exception $e) {
                    $stats[] = [
                        'name' => $client->name,
                        'slug' => $client->slug,
                        'status' => 'error',
                        'total_logs' => 0,
                        'error_logs' => 0,
                        'warning_logs' => 0,
                        'last_ingested_at' => 'Error: ' . $e->getMessage(),
                    ];
                } finally {
                    $tenantManager->resetToCentral();
                }
            }

            return $stats;
        }, 'client-logs-card');

        return view('livewire.pulse.client-logs-card', [
            'clientStats' => $clientStats,
            'time' => $time,
            'runAt' => $runAt,
        ]);
    }
}
