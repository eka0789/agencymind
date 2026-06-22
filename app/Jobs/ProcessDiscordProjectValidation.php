<?php

namespace App\Jobs;

use App\Models\Client;
use App\Domain\Tenancy\TenantAwareJob;
use App\Domain\ProjectValidation\ValidationEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessDiscordProjectValidation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJob;

    public string $projectPath;
    public int $triggeredByUserId;
    public string $jobId;
    public string $discordChannelId;
    public string $discordUserId;

    /**
     * Create a new job instance.
     */
    public function __construct(
        int $clientId,
        string $projectPath,
        int $triggeredByUserId,
        string $jobId,
        string $discordChannelId,
        string $discordUserId
    ) {
        $this->clientId = $clientId;
        $this->projectPath = $projectPath;
        $this->triggeredByUserId = $triggeredByUserId;
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
            Log::warning("ProcessDiscordProjectValidation: Client #{$this->clientId} not found.");
            return;
        }

        try {
            // 1. Run Validation Engine
            /** @var ValidationEngine $engine */
            $engine = app(ValidationEngine::class);
            $validationResult = $engine->validate($client, $this->projectPath, $this->triggeredByUserId);

            // 2. Call Discord Bot Webhook Callback
            $webhookUrl = config('services.discord.webhook_url');
            if ($webhookUrl) {
                Http::withHeaders([
                    'Authorization' => 'Bearer ' . config('services.discord.api_token')
                ])->post($webhookUrl, [
                    'job_id' => $this->jobId,
                    'type' => 'validate',
                    'discord_channel_id' => $this->discordChannelId,
                    'discord_user_id' => $this->discordUserId,
                    'client_slug' => $client->slug,
                    'result' => [
                        'overall_status' => $validationResult->overall_status,
                        'categories' => $validationResult->results_json['categories'] ?? [],
                        'ai_qualitative_assessment' => $validationResult->results_json['ai_qualitative_assessment'] ?? '',
                        'architectural_notes' => $validationResult->results_json['architectural_notes'] ?? '',
                    ]
                ]);
                Log::info("ProcessDiscordProjectValidation: Dispatched webhook callback for Job #{$this->jobId}");
            }

        } catch (\Exception $e) {
            Log::error("ProcessDiscordProjectValidation: Job #{$this->jobId} failed: " . $e->getMessage());
            
            // Send failure callback to Discord
            $webhookUrl = config('services.discord.webhook_url');
            if ($webhookUrl) {
                Http::withHeaders([
                    'Authorization' => 'Bearer ' . config('services.discord.api_token')
                ])->post($webhookUrl, [
                    'job_id' => $this->jobId,
                    'type' => 'validate',
                    'discord_channel_id' => $this->discordChannelId,
                    'discord_user_id' => $this->discordUserId,
                    'client_slug' => $client->slug,
                    'error' => 'Validation check failed: ' . $e->getMessage(),
                ]);
            }
            throw $e;
        }
    }
}
