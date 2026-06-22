<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ValidationProfile;
use App\Models\Tenant\LogSource;
use App\Models\Tenant\LogEntry;
use App\Models\Tenant\Incident;
use App\Domain\Tenancy\TenantManager;
use App\Domain\LogIngest\RuleEngine;
use App\Jobs\AnalyzeIncidentRCA;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RuleEngineTest extends TestCase
{
    use DatabaseMigrations;

    protected $tenantManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantManager = app(TenantManager::class);
    }

    public function test_rule_engine_triggers_incident_and_dispatches_job_on_breach()
    {
        Queue::fake();

        // 1. Create client
        $client = Client::create([
            'name' => 'Rule Test Client',
            'slug' => 'rule-test-client',
            'contact_email' => 'rule@test.com',
            'status' => 'active',
        ]);

        // 2. Create validation profile with threshold rules
        ValidationProfile::create([
            'client_id' => $client->id,
            'name' => 'Custom Rules',
            'rules_json' => [
                'thresholds' => [
                    [
                        'name' => 'error_spike',
                        'severity' => 'critical',
                        'metric' => 'error_count',
                        'window_minutes' => 5,
                        'limit' => 3
                    ]
                ]
            ]
        ]);

        // 3. Insert matching log entries in tenant schema
        $this->tenantManager->switchToTenant($client);

        $source = LogSource::create([
            'type' => 'push_api',
            'config_encrypted' => 'encrypted',
            'status' => 'active',
        ]);

        // Create 3 error logs within 5 minutes
        for ($i = 0; $i < 3; $i++) {
            LogEntry::create([
                'log_source_id' => $source->id,
                'timestamp' => now()->subMinutes(1),
                'level' => 'error',
                'source_type' => 'laravel',
                'raw_message' => "Error matching line {$i}",
                'is_parsed' => true,
            ]);
        }

        // Create 1 error log outside window (should be ignored)
        LogEntry::create([
            'log_source_id' => $source->id,
            'timestamp' => now()->subMinutes(10),
            'level' => 'error',
            'source_type' => 'laravel',
            'raw_message' => "Old error log",
            'is_parsed' => true,
        ]);

        // Run Rule Engine
        $engine = new RuleEngine();
        $engine->evaluate($client);

        // 4. Assertions
        $incidents = Incident::all();
        $this->assertCount(1, $incidents);
        $this->assertEquals('critical', $incidents[0]->severity);
        $this->assertStringContainsString('error_spike', $incidents[0]->title);
        $this->assertCount(3, $incidents[0]->related_log_entry_ids);

        // Verify AnalyzeIncidentRCA job was dispatched to critical queue
        Queue::assertPushed(AnalyzeIncidentRCA::class, function ($job) use ($client, $incidents) {
            return $job->clientId === $client->id
                && $job->incidentId === $incidents[0]->id
                && $job->queue === 'critical';
        });

        $this->tenantManager->resetToCentral();
    }

    public function test_rule_engine_does_not_trigger_incident_if_below_threshold()
    {
        Queue::fake();

        $client = Client::create([
            'name' => 'Rule Test Client 2',
            'slug' => 'rule-test-client-2',
            'contact_email' => 'rule2@test.com',
            'status' => 'active',
        ]);

        ValidationProfile::create([
            'client_id' => $client->id,
            'name' => 'Custom Rules',
            'rules_json' => [
                'thresholds' => [
                    [
                        'name' => 'error_spike',
                        'severity' => 'critical',
                        'metric' => 'error_count',
                        'window_minutes' => 5,
                        'limit' => 3
                    ]
                ]
            ]
        ]);

        $this->tenantManager->switchToTenant($client);

        $source = LogSource::create([
            'type' => 'push_api',
            'config_encrypted' => 'encrypted',
            'status' => 'active',
        ]);

        // Only 2 errors (limit is 3)
        for ($i = 0; $i < 2; $i++) {
            LogEntry::create([
                'log_source_id' => $source->id,
                'timestamp' => now()->subMinutes(1),
                'level' => 'error',
                'source_type' => 'laravel',
                'raw_message' => "Error matching line {$i}",
                'is_parsed' => true,
            ]);
        }

        $engine = new RuleEngine();
        $engine->evaluate($client);

        $this->assertCount(0, Incident::all());
        Queue::assertNotPushed(AnalyzeIncidentRCA::class);

        $this->tenantManager->resetToCentral();
    }

    public function test_rule_engine_deduplicates_open_incidents()
    {
        Queue::fake();

        $client = Client::create([
            'name' => 'Rule Test Client 3',
            'slug' => 'rule-test-client-3',
            'contact_email' => 'rule3@test.com',
            'status' => 'active',
        ]);

        ValidationProfile::create([
            'client_id' => $client->id,
            'name' => 'Custom Rules',
            'rules_json' => [
                'thresholds' => [
                    [
                        'name' => 'error_spike',
                        'severity' => 'critical',
                        'metric' => 'error_count',
                        'window_minutes' => 5,
                        'limit' => 3
                    ]
                ]
            ]
        ]);

        $this->tenantManager->switchToTenant($client);

        $source = LogSource::create([
            'type' => 'push_api',
            'config_encrypted' => 'encrypted',
            'status' => 'active',
        ]);

        // Create an existing open incident for this rule
        Incident::create([
            'severity' => 'critical',
            'title' => 'Threshold breached: error_spike (5 matches)',
            'related_log_entry_ids' => [1, 2],
            'status' => 'open',
        ]);

        // Create 4 error logs (would normally trigger)
        for ($i = 0; $i < 4; $i++) {
            LogEntry::create([
                'log_source_id' => $source->id,
                'timestamp' => now()->subMinutes(1),
                'level' => 'error',
                'source_type' => 'laravel',
                'raw_message' => "Error matching line {$i}",
                'is_parsed' => true,
            ]);
        }

        $engine = new RuleEngine();
        $engine->evaluate($client);

        // Still should only have the 1 existing incident (deduplicated)
        $this->assertCount(1, Incident::all());
        Queue::assertNotPushed(AnalyzeIncidentRCA::class);

        $this->tenantManager->resetToCentral();
    }
}
