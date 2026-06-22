<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\AuditLog;
use App\Models\ValidationProfile;
use App\Models\Tenant\LogSource;
use App\Models\Tenant\LogEntry;
use App\Models\Tenant\Incident;
use App\Models\Tenant\ValidationResult;
use App\Domain\Tenancy\TenantManager;
use App\Jobs\ProcessDiscordLogCheck;
use App\Jobs\ProcessDiscordProjectValidation;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DiscordCommandTest extends TestCase
{
    use DatabaseMigrations;

    protected $tenantManager;
    protected $token = 'bot-secret-token';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantManager = app(TenantManager::class);
        config(['services.discord.api_token' => $this->token]);
        config(['services.discord.webhook_url' => 'http://localhost:3000/api/v1/webhooks/job-result']);
        config(['services.openai.key' => 'test-key']);
    }

    public function test_log_check_endpoint_dispatches_job_and_logs_audit()
    {
        Queue::fake();

        $client = Client::create([
            'name' => 'Discord Client 1',
            'slug' => 'discord-client-1',
            'contact_email' => 'discord1@test.com',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'discord_user_id' => 'discord-user-123',
            'role' => 'developer',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}"
        ])->postJson('/api/v1/commands/log-check', [
            'client_slug' => 'discord-client-1',
            'range' => '24h',
            'discord_channel_id' => 'chan-456',
            'requested_by_discord_id' => 'discord-user-123',
        ]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['job_id', 'status']);

        // Assert audit log is written
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'client_id' => $client->id,
            'action_type' => 'log_check',
        ]);

        // Assert job pushed to queue
        Queue::assertPushed(ProcessDiscordLogCheck::class, function ($job) use ($client) {
            return $job->clientId === $client->id
                && $job->range === '24h'
                && $job->discordChannelId === 'chan-456'
                && $job->discordUserId === 'discord-user-123'
                && $job->queue === 'critical';
        });
    }

    public function test_validate_endpoint_dispatches_job_and_logs_audit()
    {
        Queue::fake();

        $client = Client::create([
            'name' => 'Discord Client 2',
            'slug' => 'discord-client-2',
            'contact_email' => 'discord2@test.com',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'discord_user_id' => 'discord-user-456',
            'role' => 'lead_dev',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}"
        ])->postJson('/api/v1/commands/validate', [
            'project_slug' => 'discord-client-2',
            'discord_channel_id' => 'chan-789',
            'requested_by_discord_id' => 'discord-user-456',
            'project_path' => '/var/www/mock-project',
        ]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['job_id', 'status']);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'client_id' => $client->id,
            'action_type' => 'validate',
        ]);

        Queue::assertPushed(ProcessDiscordProjectValidation::class, function ($job) use ($client) {
            return $job->clientId === $client->id
                && $job->projectPath === '/var/www/mock-project'
                && $job->discordChannelId === 'chan-789'
                && $job->discordUserId === 'discord-user-456'
                && $job->queue === 'critical';
        });
    }

    public function test_threshold_set_endpoint_updates_profile_limits()
    {
        $client = Client::create([
            'name' => 'Discord Client 3',
            'slug' => 'discord-client-3',
            'contact_email' => 'discord3@test.com',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'discord_user_id' => 'discord-user-789',
            'role' => 'owner',
        ]);

        $profile = ValidationProfile::create([
            'client_id' => $client->id,
            'name' => 'Initial Profile',
            'rules_json' => [
                'thresholds' => [
                    [
                        'name' => 'error_spike',
                        'severity' => 'critical',
                        'metric' => 'error_count',
                        'window_minutes' => 5,
                        'limit' => 5
                    ]
                ]
            ]
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}"
        ])->postJson('/api/v1/commands/threshold-set', [
            'client_slug' => 'discord-client-3',
            'parameter' => 'error_spike',
            'value' => 20,
            'requested_by_discord_id' => 'discord-user-789',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'client_id' => $client->id,
            'action_type' => 'threshold_set',
        ]);

        $updatedProfile = ValidationProfile::find($profile->id);
        $this->assertEquals(20, $updatedProfile->rules_json['thresholds'][0]['limit']);
    }

    public function test_detailed_status_endpoint_returns_health_metrics()
    {
        $client = Client::create([
            'name' => 'Discord Client 4',
            'slug' => 'discord-client-4',
            'contact_email' => 'discord4@test.com',
            'status' => 'active',
        ]);

        // Ensure ValidationProfile exists for client status lookup
        ValidationProfile::create([
            'client_id' => $client->id,
            'name' => 'Profile',
            'rules_json' => []
        ]);

        $this->tenantManager->switchToTenant($client);

        // Add mock open incident and last validation result
        Incident::create([
            'severity' => 'critical',
            'title' => 'Spike',
            'related_log_entry_ids' => [1],
            'status' => 'open'
        ]);

        ValidationResult::create([
            'validation_profile_id' => 1,
            'triggered_by_user_id' => 1,
            'overall_status' => 'pass_with_notes',
            'results_json' => []
        ]);

        $this->tenantManager->resetToCentral();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}"
        ])->getJson("/api/v1/clients/discord-client-4/status-detailed");

        $response->assertStatus(200);
        $response->assertJson([
            'client' => 'discord-client-4',
            'health' => 'critical',
            'open_incidents' => 1,
            'last_validation' => [
                'overall_status' => 'pass_with_notes'
            ]
        ]);
    }

    public function test_job_log_check_sends_webhook_callback()
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Logs look quiet.'
                        ]
                    ]
                ]
            ], 200),
            'http://localhost:3000/api/v1/webhooks/job-result' => Http::response([], 200),
        ]);

        $client = Client::create([
            'name' => 'Discord Job Client',
            'slug' => 'discord-job-client',
            'contact_email' => 'job@test.com',
            'status' => 'active',
        ]);

        $this->tenantManager->switchToTenant($client);

        $source = LogSource::create([
            'type' => 'push_api',
            'config_encrypted' => 'enc',
            'status' => 'active'
        ]);

        LogEntry::create([
            'log_source_id' => $source->id,
            'timestamp' => now(),
            'level' => 'info',
            'source_type' => 'nginx',
            'raw_message' => 'Info log message',
            'is_parsed' => true
        ]);

        // Run Job handle directly inside tenant context
        $this->tenantManager->switchToTenant($client);
        $job = new ProcessDiscordLogCheck($client->id, '24h', 'job-id-111', 'chan-222', 'usr-333');
        $job->handle();
        $this->tenantManager->resetToCentral();

        // Assert webhook HTTP request was sent to bot server
        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:3000/api/v1/webhooks/job-result'
                && $request->hasHeader('Authorization', 'Bearer ' . $this->token)
                && $request['job_id'] === 'job-id-111'
                && $request['type'] === 'log_check'
                && $request['discord_channel_id'] === 'chan-222'
                && $request['discord_user_id'] === 'usr-333'
                && isset($request['result']['summary']);
        });
    }
}
