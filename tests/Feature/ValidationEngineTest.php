<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ValidationProfile;
use App\Models\Tenant\ValidationResult;
use App\Domain\Tenancy\TenantManager;
use App\Domain\ProjectValidation\ValidationEngine;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ValidationEngineTest extends TestCase
{
    use DatabaseMigrations;

    protected $tenantManager;
    protected $mockPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantManager = app(TenantManager::class);
        $this->mockPath = storage_path('app/mock_project');
        config(['services.openai.key' => 'test-key']);
        
        if (File::isDirectory($this->mockPath)) {
            File::deleteDirectory($this->mockPath);
        }
        File::makeDirectory($this->mockPath, 0755, true);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->mockPath)) {
            File::deleteDirectory($this->mockPath);
        }
        parent::tearDown();
    }

    public function test_validation_engine_runs_rule_checks_and_ai_review()
    {
        // 1. Create files that trigger failures
        File::put($this->mockPath . '/.env', "APP_ENV=production\nAPP_DEBUG=true\n");
        File::put($this->mockPath . '/.gitignore', "*.log\n"); // .env is NOT ignored
        File::put($this->mockPath . '/index.php', "<?php\n\$api_key = \"my-super-secret-token-key\";\n");
        // README.md is intentionally missing

        // 2. Setup database entries
        $client = Client::create([
            'name' => 'Validation Test Client',
            'slug' => 'val-test-client',
            'contact_email' => 'val@test.com',
            'status' => 'active',
        ]);

        ValidationProfile::create([
            'client_id' => $client->id,
            'name' => 'Client SOP Profile',
            'rules_json' => [
                'security' => [
                    'check_committed_env' => true,
                    'check_app_debug' => true,
                    'check_secrets' => true,
                ]
            ]
        ]);

        $this->tenantManager->switchToTenant($client);

        // 3. Fake OpenAI gateway response
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'assessment' => 'Standard project layout with minor observations.',
                                'notes' => 'Controllers and structure look average. Please add README.md.'
                            ])
                        ]
                    ]
                ]
            ], 200)
        ]);

        // 4. Run Validation Engine
        /** @var ValidationEngine $engine */
        $engine = app(ValidationEngine::class);
        $result = $engine->validate($client, $this->mockPath, 1);

        // 5. Assertions
        $this->assertEquals('fail', $result->overall_status);
        $details = $result->results_json;

        // Security checks checks
        $securityItems = collect($details['categories']['security']);
        $committedEnv = $securityItems->firstWhere('name', 'No committed .env files');
        $this->assertEquals('fail', $committedEnv['status']);
        $this->assertEquals('blocker', $committedEnv['severity']);

        $appDebug = $securityItems->firstWhere('name', 'APP_DEBUG set to false');
        $this->assertEquals('fail', $appDebug['status']);
        $this->assertEquals('blocker', $appDebug['severity']);

        $hardcodedCreds = $securityItems->firstWhere('name', 'No hardcoded credentials');
        $this->assertEquals('fail', $hardcodedCreds['status']);
        $this->assertEquals('major', $hardcodedCreds['severity']);

        // Documentation checks
        $docItems = collect($details['categories']['documentation']);
        $readme = $docItems->firstWhere('name', 'README.md exists');
        $this->assertEquals('fail', $readme['status']);
        $this->assertEquals('major', $readme['severity']);

        // AI assessment checks
        $this->assertEquals('Standard project layout with minor observations.', $details['ai_qualitative_assessment']);
        $this->assertEquals('Controllers and structure look average. Please add README.md.', $details['architectural_notes']);

        // Verify stored in DB
        $dbResult = ValidationResult::find($result->id);
        $this->assertNotNull($dbResult);
        $this->assertEquals('fail', $dbResult->overall_status);

        $this->tenantManager->resetToCentral();
    }
}
