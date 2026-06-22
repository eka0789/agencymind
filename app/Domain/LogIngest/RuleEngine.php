<?php

namespace App\Domain\LogIngest;

use App\Models\Client;
use App\Models\ValidationProfile;
use App\Models\Tenant\LogEntry;
use App\Models\Tenant\Incident;
use App\Jobs\AnalyzeIncidentRCA;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class RuleEngine
{
    /**
     * Evaluate rules for a specific client tenant.
     */
    public function evaluate(Client $client): void
    {
        // 1. Get the validation profile rules for this client
        $profile = ValidationProfile::where('client_id', $client->id)->first()
            ?: ValidationProfile::whereNull('client_id')->first();

        if (!$profile || empty($profile->rules_json['thresholds'])) {
            Log::info("RuleEngine: No threshold rules found for client [{$client->slug}].");
            return;
        }

        if (!Schema::hasTable('log_entries')) {
            Log::warning("RuleEngine: Table log_entries does not exist in schema for client [{$client->slug}].");
            return;
        }

        $thresholds = $profile->rules_json['thresholds'];

        foreach ($thresholds as $rule) {
            $name = $rule['name'] ?? 'unknown_rule';
            $severity = $rule['severity'] ?? 'warning';
            $windowMinutes = $rule['window_minutes'] ?? 5;
            $limit = $rule['limit'] ?? 10;
            $metric = $rule['metric'] ?? 'error_count';

            // Check if there is already an open incident for this rule to deduplicate
            $existingIncident = Incident::where('status', 'open')
                ->where('title', 'like', "%Threshold breached: {$name}%")
                ->first();

            if ($existingIncident) {
                continue;
            }

            // Fetch logs within window
            $since = now()->subMinutes($windowMinutes);
            $query = LogEntry::where('timestamp', '>=', $since);

            if ($metric === 'error_count') {
                $query->whereIn('level', ['error', 'critical', 'alert', 'emergency', 'fail']);
            } elseif ($metric === 'warning_count') {
                $query->where('level', 'warning');
            } elseif ($metric === 'http_500_count') {
                $query->where('http_status', '>=', 500)->where('http_status', '<', 600);
            }

            $matchingLogs = $query->orderBy('timestamp', 'desc')->get();
            $count = $matchingLogs->count();

            if ($count >= $limit) {
                Log::warning("RuleEngine: Anomaly detected for client [{$client->slug}]. Rule [{$name}] breached: {$count} logs (limit: {$limit}).");

                // Create a new incident
                $incident = Incident::create([
                    'severity' => $severity,
                    'title' => "Threshold breached: {$name} ({$count} matches)",
                    'related_log_entry_ids' => $matchingLogs->pluck('id')->toArray(),
                    'status' => 'open',
                ]);

                // Dispatch the asynchronous Log Analyst Agent job
                AnalyzeIncidentRCA::dispatch($client->id, $incident->id)->onQueue('critical');
            }
        }
    }
}
