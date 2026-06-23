<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Tenant\LogEntry;
use App\Domain\Tenancy\TenantAwareJob;
use App\Services\LLMGatewayManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessDiscordLogCheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJob;

    public string $range;
    public string $jobId;
    public string $discordChannelId;
    public string $discordUserId;

    /**
     * Create a new job instance.
     */
    public function __construct(
        int $clientId,
        string $range,
        string $jobId,
        string $discordChannelId,
        string $discordUserId
    ) {
        $this->clientId = $clientId;
        $this->range = $range;
        $this->jobId = $jobId;
        $this->discordChannelId = $discordChannelId;
        $this->discordUserId = $discordUserId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $client = Client::find($this->clientId);
        if (!$client) {
            Log::warning("ProcessDiscordLogCheck: Client #{$this->clientId} not found.");
            return;
        }

        try {
            // Parse time range
            $hours = 24;
            $rangeLower = strtolower($this->range);
            if (str_ends_with($rangeLower, 'h')) {
                $hours = (int) substr($rangeLower, 0, -1);
            } elseif (str_ends_with($rangeLower, 'd')) {
                $hours = (int) substr($rangeLower, 0, -1) * 24;
            } elseif (str_ends_with($rangeLower, 'm')) {
                $hours = max(1, ceil((int) substr($rangeLower, 0, -1) / 60));
            }

            $since = now()->subHours($hours);
            $logEntries = LogEntry::where('timestamp', '>=', $since)
                ->orderBy('timestamp', 'desc')
                ->limit(100) // limit to avoid massive context
                ->get();

            // Scrub and format logs
            $formattedLogs = '';
            foreach ($logEntries as $entry) {
                $raw = "ID: {$entry->id} | Time: {$entry->timestamp} | Level: {$entry->level} | Source: {$entry->source_type} | HTTP: {$entry->http_status}\nMessage: {$entry->raw_message}\n";
                if (!empty($entry->stack_trace)) {
                    $raw .= "Stack Trace:\n{$entry->stack_trace}\n";
                }
                $raw .= "----------------------------------------\n";
                $formattedLogs .= $this->scrubSensitiveData($raw);
            }

            if ($logEntries->isEmpty()) {
                $summary = "No log entries found for the last {$this->range}. Everything looks quiet!";
            } else {
                // Call LLM Gateway
                /** @var LLMGatewayManager $llmManager */
                $llmManager = app(LLMGatewayManager::class);
                $systemPrompt = "You are the Log Summary Agent for AgencyMind. Your task is to analyze the log entries for the last {$this->range} for client {$client->name} and provide a concise technical summary of any errors, warnings, or performance issues, along with recommendations.";
                
                $summary = $llmManager->analyzeWithFallback(
                    "Here are the recent log entries:\n\n{$formattedLogs}",
                    $systemPrompt,
                    [
                        'client_id' => $this->clientId,
                        'task_type' => 'log_check',
                    ]
                );
            }

            // Post result back to Discord bot webhook
            $webhookUrl = config('services.discord.webhook_url');
            if ($webhookUrl) {
                Http::withHeaders([
                    'Authorization' => 'Bearer ' . config('services.discord.api_token')
                ])->post($webhookUrl, [
                    'job_id' => $this->jobId,
                    'type' => 'log_check',
                    'discord_channel_id' => $this->discordChannelId,
                    'discord_user_id' => $this->discordUserId,
                    'client_slug' => $client->slug,
                    'result' => [
                        'summary' => $summary,
                    ]
                ]);
                Log::info("ProcessDiscordLogCheck: Dispatched webhook callback for Job #{$this->jobId}");
            }

        } catch (\Exception $e) {
            Log::error("ProcessDiscordLogCheck: Job #{$this->jobId} failed: " . $e->getMessage());
            
            // Send failure callback
            $webhookUrl = config('services.discord.webhook_url');
            if ($webhookUrl) {
                Http::withHeaders([
                    'Authorization' => 'Bearer ' . config('services.discord.api_token')
                ])->post($webhookUrl, [
                    'job_id' => $this->jobId,
                    'type' => 'log_check',
                    'discord_channel_id' => $this->discordChannelId,
                    'discord_user_id' => $this->discordUserId,
                    'client_slug' => $client->slug,
                    'error' => 'Log check analysis failed: ' . $e->getMessage(),
                ]);
            }
            throw $e;
        }
    }

    /**
     * Scrub sensitive information from logs before sending to external LLM.
     */
    protected function scrubSensitiveData(string $text): string
    {
        // Email scrubbing
        $text = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[EMAIL_REDACTED]', $text);
        // IPv4 scrubbing
        $text = preg_replace('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', '[IP_REDACTED]', $text);
        // Secret/password scrubbing
        $text = preg_replace('/(password|pass|secret|token|key|api_key|auth_token)\s*=\s*[^\s&"]+/', '$1=[REDACTED]', $text);
        $text = preg_replace('/"(password|pass|secret|token|key|api_key|auth_token)"\s*:\s*"[^"]*"/', '"$1":"[REDACTED]"', $text);

        return $text;
    }
}
