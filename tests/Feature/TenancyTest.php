<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Tenant\LogSource;
use App\Models\Tenant\LogEntry;
use App\Domain\Tenancy\TenantManager;
use App\Domain\Tenancy\TenantAwareJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Tests\TestCase;

// Dummy Job class for testing TenantAwareJob
class TestTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels, TenantAwareJob;

    public static $lastExecutedSchema = null;

    public function __construct($clientId)
    {
        $this->clientId = $clientId;
    }

    public function handle()
    {
        // Capture active search path during execution
        self::$lastExecutedSchema = DB::select("SHOW search_path")[0]->search_path ?? 'public';
    }
}

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    protected $tenantManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantManager = app(TenantManager::class);
        $this->tenantManager->resetToCentral();
    }

    public function test_schemas_are_isolated_and_independent()
    {
        // 1. Create Client A and Client B
        $clientA = Client::create([
            'name' => 'Client A',
            'slug' => 'client-a',
            'contact_email' => 'client-a@test.com',
            'status' => 'active',
        ]);

        $clientB = Client::create([
            'name' => 'Client B',
            'slug' => 'client-b',
            'contact_email' => 'client-b@test.com',
            'status' => 'active',
        ]);

        // Verify schemas were created in database
        $schemaAExists = DB::select("SELECT 1 FROM pg_namespace WHERE nspname = ?", [$clientA->schema_name]);
        $schemaBExists = DB::select("SELECT 1 FROM pg_namespace WHERE nspname = ?", [$clientB->schema_name]);
        
        $this->assertNotEmpty($schemaAExists, "Schema for Client A should exist");
        $this->assertNotEmpty($schemaBExists, "Schema for Client B should exist");

        // 2. Insert log entries into Client A schema
        $this->tenantManager->switchToTenant($clientA);
        
        $sourceA = LogSource::create([
            'type' => 'push_api',
            'config_encrypted' => 'config_a',
            'status' => 'active',
        ]);

        $logA = LogEntry::create([
            'log_source_id' => $sourceA->id,
            'timestamp' => now(),
            'level' => 'error',
            'source_type' => 'laravel',
            'raw_message' => 'Error in Client A',
            'is_parsed' => true,
        ]);

        $this->assertCount(1, LogEntry::all());
        $this->assertEquals('Error in Client A', LogEntry::first()->raw_message);

        // 3. Switch to Client B schema
        $this->tenantManager->switchToTenant($clientB);

        // Assert: LogEntry list is empty in Client B schema
        $this->assertCount(0, LogEntry::all());

        // Create log entry in Client B schema
        $sourceB = LogSource::create([
            'type' => 'push_api',
            'config_encrypted' => 'config_b',
            'status' => 'active',
        ]);

        $logB = LogEntry::create([
            'log_source_id' => $sourceB->id,
            'timestamp' => now(),
            'level' => 'warning',
            'source_type' => 'laravel',
            'raw_message' => 'Warning in Client B',
            'is_parsed' => true,
        ]);

        $this->assertCount(1, LogEntry::all());
        $this->assertEquals('Warning in Client B', LogEntry::first()->raw_message);

        // 4. Switch back to Client A schema and verify
        $this->tenantManager->switchToTenant($clientA);
        $this->assertCount(1, LogEntry::all());
        $this->assertEquals('Error in Client A', LogEntry::first()->raw_message);

        // 5. Reset to central schema
        $this->tenantManager->resetToCentral();
    }

    public function test_tenant_aware_job_sets_search_path()
    {
        $client = Client::create([
            'name' => 'Client Job Test',
            'slug' => 'client-job-test',
            'contact_email' => 'client-job@test.com',
            'status' => 'active',
        ]);

        $job = new TestTenantJob($client->id);
        
        // Directly execute the SwitchTenantSchema job middleware
        $middleware = new \App\Domain\Tenancy\Middleware\SwitchTenantSchema($client->id);
        
        $middleware->handle($job, function ($j) use ($client) {
            // Check search path inside job execution was client's schema name
            $activeSchema = DB::select("SHOW search_path")[0]->search_path ?? 'public';
            $this->assertStringContainsString($client->schema_name, $activeSchema);
        });

        // Verify that after job execution search path is reset to public
        $currentSearchPath = DB::select("SHOW search_path")[0]->search_path ?? 'public';
        $this->assertEquals('public', $currentSearchPath);
    }
}
