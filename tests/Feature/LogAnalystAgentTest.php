<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Tenant\LogSource;
use App\Models\Tenant\LogEntry;
use App\Models\Tenant\Incident;
use App\Domain\Tenancy\TenantManager;
use App\Jobs\AnalyzeIncidentRCA;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LogAnalystAgentTest extends TestCase
{
    use DatabaseMigrations;

    protected $tenantManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantManager = app(TenantManager::class);
        config(['services.openai.key' => 'test-key']);
    }

    public function test_log_analyst_agent_scrubs_logs_and_saves_rca()
    {
        // 1. Setup client & log source
        $client = Client::create([
            'name' => 'Agent Test Client',
            'slug' => 'agent-test-client',
            'contact_email' => 'agent@test.com',
            'status' => 'active',
        ]);

        $this->tenantManager->switchToTenant($client);

        $source = LogSource::create([
            'type' => 'push_api',
            'config_encrypted' => 'encrypted',
            'status' => 'active',
        ]);

        // 2. Setup logs with sensitive data (email, IP, secret)
        $log1 = LogEntry::create([
            'log_source_id' => $source->id,
            'timestamp' => now(),
            'level' => 'error',
            'source_type' => 'laravel',
            'raw_message' => 'Connection failed for user john.doe@secretcompany.com on host 192.168.1.100 with password=my-super-secret-pass',
            'stack_trace' => "Stack trace here\nat line 45",
            'is_parsed' => true,
        ]);

        // 3. Create incident
        $incident = Incident::create([
            'severity' => 'critical',
            'title' => 'Threshold breached: connection_error',
            'related_log_entry_ids' => [$log1->id],
            'status' => 'open',
        ]);

        // 4. Fake HTTP call to OpenAI
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'confidence' => 'high',
                                'summary' => '## Root Cause Analysis\\n\\nVerified failure.'
                            ])
                        ]
                    ]
                ]
            ], 200)
        ]);

        // 5. Execute job directly
        $job = new AnalyzeIncidentRCA($client->id, $incident->id);
        $job->handle();

        // 6. Assertions
        $updatedIncident = Incident::find($incident->id);
        $this->assertEquals('high', $updatedIncident->rca_confidence);
        $this->assertEquals('## Root Cause Analysis\n\nVerified failure.', $updatedIncident->rca_summary);

        // Verify that the prompt sent to OpenAI was scrubbed of sensitive data
        Http::assertSent(function ($request) {
            $body = $request->body();
            // Should contain the redacted indicators
            $this->assertStringContainsString('[EMAIL_REDACTED]', $body);
            $this->assertStringContainsString('[IP_REDACTED]', $body);
            $this->assertStringContainsString('password=[REDACTED]', $body);
            // Should NOT contain the original sensitive values
            $this->assertStringNotContainsString('john.doe@secretcompany.com', $body);
            $this->assertStringNotContainsString('192.168.1.100', $body);
            $this->assertStringNotContainsString('my-super-secret-pass', $body);

            return true;
        });

        $this->tenantManager->resetToCentral();
    }
}
