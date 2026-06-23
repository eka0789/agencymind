<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Tenant\Incident;
use App\Models\Tenant\ValidationResult;
use App\Models\Tenant\WeeklyReport;
use App\Domain\Tenancy\TenantManager;
use App\Jobs\GenerateWeeklyReport;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReportingAgentTest extends TestCase
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

    public function test_reporting_agent_generates_draft_and_sends_webhook()
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Laporan Mingguan: Semua aman terkendali.'
                        ]
                    ]
                ]
            ], 200),
            'http://localhost:3000/api/v1/webhooks/job-result' => Http::response([], 200),
        ]);

        $client = Client::create([
            'name' => 'Report Client',
            'slug' => 'report-client',
            'contact_email' => 'report@test.com',
            'status' => 'active',
        ]);

        $this->tenantManager->switchToTenant($client);

        Incident::create([
            'severity' => 'warning',
            'title' => 'Slow Query',
            'related_log_entry_ids' => [1, 2],
            'status' => 'resolved',
            'rca_summary' => 'Index missing'
        ]);

        ValidationResult::create([
            'validation_profile_id' => 1,
            'triggered_by_user_id' => 1,
            'overall_status' => 'pass',
            'results_json' => []
        ]);

        $job = new GenerateWeeklyReport($client->id, 'job-id-123', 'chan-123', 'usr-123', 7);
        $job->handle();

        $report = WeeklyReport::first();
        $this->assertNotNull($report);
        $this->assertEquals('draft', $report->status);
        $this->assertStringContainsString('Laporan Mingguan: Semua aman terkendali.', $report->draft_content);

        $this->tenantManager->resetToCentral();

        Http::assertSent(function ($request) use ($report) {
            return $request->url() === 'http://localhost:3000/api/v1/webhooks/job-result'
                && $request->hasHeader('Authorization', 'Bearer ' . $this->token)
                && $request['job_id'] === 'job-id-123'
                && $request['type'] === 'report_generated'
                && $request['discord_channel_id'] === 'chan-123'
                && $request['discord_user_id'] === 'usr-123'
                && $request['result']['report_id'] === $report->id;
        });
    }
}
