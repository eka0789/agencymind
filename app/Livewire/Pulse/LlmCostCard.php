<?php

namespace App\Livewire\Pulse;

use App\Models\Client;
use App\Models\LlmUsage;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

#[Lazy]
class LlmCostCard extends Card
{
    /**
     * Render the component.
     */
    public function render()
    {
        [$costStats, $time, $runAt] = $this->remember(function () {
            $interval = $this->periodAsInterval();
            $since = now()->sub($interval);

            // Group by client
            $usages = LlmUsage::with('client')
                ->where('created_at', '>=', $since)
                ->get();

            $stats = [];
            foreach ($usages as $usage) {
                $clientId = $usage->client_id ?? 0;
                if (!isset($stats[$clientId])) {
                    $stats[$clientId] = [
                        'client_name' => $usage->client ? $usage->client->name : 'System (No Client)',
                        'total_tokens' => 0,
                        'total_cost' => 0.0,
                        'job_count' => 0,
                    ];
                }

                $stats[$clientId]['total_tokens'] += ($usage->prompt_tokens + $usage->completion_tokens);
                $stats[$clientId]['total_cost'] += (float) $usage->estimated_cost_usd;
                $stats[$clientId]['job_count']++;
            }

            // Also include active clients with 0 usage
            $clients = Client::all();
            foreach ($clients as $client) {
                if (!isset($stats[$client->id])) {
                    $stats[$client->id] = [
                        'client_name' => $client->name,
                        'total_tokens' => 0,
                        'total_cost' => 0.0,
                        'job_count' => 0,
                    ];
                }
            }

            // Sort by cost descending
            usort($stats, function ($a, $b) {
                return $b['total_cost'] <=> $a['total_cost'];
            });

            return $stats;
        }, 'llm-cost-card');

        return view('livewire.pulse.llm-cost-card', [
            'costStats' => $costStats,
            'time' => $time,
            'runAt' => $runAt,
        ]);
    }
}
