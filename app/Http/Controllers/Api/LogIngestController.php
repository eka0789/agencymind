<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\LogEntry;
use App\Models\Tenant\LogSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class LogIngestController extends Controller
{
    public function ingest(Request $request, $clientSlug)
    {
        $logSource = Auth::user();

        // 1. Verify the authenticated tokenable is a LogSource
        if (!($logSource instanceof LogSource)) {
            return response()->json(['error' => 'Unauthorized: Invalid log source token'], 401);
        }

        $client = $request->attributes->get('client');
        if (!$client) {
            return response()->json(['error' => 'Client context not set'], 500);
        }

        // 2. Validate input payload
        $payload = $request->all();
        
        // Support both batch format {"logs": [...]} and single record format
        $entries = isset($payload['logs']) && is_array($payload['logs']) ? $payload['logs'] : [$payload];

        $insertedCount = 0;

        foreach ($entries as $entry) {
            // Check for empty payload
            if (empty($entry)) {
                continue;
            }

            try {
                // Parse and normalize timestamp
                $timestamp = isset($entry['timestamp']) ? Carbon::parse($entry['timestamp']) : now();
                $level = strtolower($entry['level'] ?? 'info');
                $sourceType = $entry['source_type'] ?? 'push_api';
                $rawMessage = $entry['raw_message'] ?? null;
                
                // If message is missing, fallback to JSON representation of the entry
                if ($rawMessage === null) {
                    $rawMessage = is_string($entry) ? $entry : json_encode($entry);
                    $isParsed = false;
                } else {
                    $isParsed = true;
                }

                LogEntry::create([
                    'log_source_id' => $logSource->id,
                    'timestamp' => $timestamp,
                    'level' => $level,
                    'source_type' => $sourceType,
                    'raw_message' => $rawMessage,
                    'stack_trace' => $entry['stack_trace'] ?? null,
                    'http_status' => isset($entry['http_status']) ? intval($entry['http_status']) : null,
                    'context_json' => isset($entry['context_json']) ? (is_array($entry['context_json']) ? $entry['context_json'] : json_decode($entry['context_json'], true)) : null,
                    'is_parsed' => $isParsed,
                ]);

                $insertedCount++;
            } catch (\Exception $e) {
                // Save unparsed raw entry on error
                LogEntry::create([
                    'log_source_id' => $logSource->id,
                    'timestamp' => now(),
                    'level' => 'error',
                    'source_type' => 'push_api',
                    'raw_message' => is_string($entry) ? $entry : json_encode($entry),
                    'is_parsed' => false,
                ]);
                $insertedCount++;
            }
        }

        // Trigger rule engine evaluation
        try {
            $ruleEngine = new \App\Domain\LogIngest\RuleEngine();
            $ruleEngine->evaluate($client);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to run RuleEngine for client [{$client->slug}]: " . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => "Successfully ingested {$insertedCount} log entries",
        ], 200);
    }
}
