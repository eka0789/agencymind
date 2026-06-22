<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Tenant\LogSource;
use App\Models\Tenant\LogEntry;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LogIngestTest extends TestCase
{
    use DatabaseMigrations;

    protected $tenantManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantManager = app(TenantManager::class);
        $this->tenantManager->resetToCentral();
    }

    public function test_push_api_ingests_logs_successfully()
    {
        $client = Client::create([
            'name' => 'Push Client',
            'slug' => 'push-client',
            'contact_email' => 'push@test.com',
            'status' => 'active',
        ]);

        $this->tenantManager->switchToTenant($client);

        $source = LogSource::create([
            'type' => 'push_api',
            'config_encrypted' => Crypt::encryptString(json_encode([])),
            'status' => 'active',
        ]);

        // Generate token for this log source
        $token = $source->createToken('log-source-token')->plainTextToken;

        $this->tenantManager->resetToCentral();

        // 1. Push logs using the token
        $payload = [
            'logs' => [
                [
                    'timestamp' => '2026-06-22 10:00:00',
                    'level' => 'ERROR',
                    'source_type' => 'laravel',
                    'raw_message' => 'Exception in code',
                    'http_status' => 500,
                    'context_json' => ['user_id' => 1]
                ],
                [
                    // Missing raw_message (unparsed)
                    'timestamp' => '2026-06-22 10:01:00',
                    'level' => 'INFO',
                    'source_type' => 'nginx',
                    'http_status' => 200,
                ]
            ]
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/clients/{$client->slug}/logs", $payload);

        $response->assertStatus(200);

        // Switch to tenant schema to check database
        $this->tenantManager->switchToTenant($client);

        $this->assertCount(2, LogEntry::all());
        
        $entry1 = LogEntry::where('level', 'error')->first();
        $this->assertEquals('Exception in code', $entry1->raw_message);
        $this->assertEquals(500, $entry1->http_status);
        $this->assertEquals(1, $entry1->context_json['user_id']);
        $this->assertTrue($entry1->is_parsed);

        $entry2 = LogEntry::where('level', 'info')->first();
        $this->assertFalse($entry2->is_parsed);

        $this->tenantManager->resetToCentral();
    }

    public function test_ssh_pull_job_connects_and_ingests_logs()
    {
        // 1. Create client and log source
        $client = Client::create([
            'name' => 'SSH Client',
            'slug' => 'ssh-client',
            'contact_email' => 'ssh@test.com',
            'status' => 'active',
        ]);

        $this->tenantManager->switchToTenant($client);

        // Encrypted config
        $config = [
            'host' => '127.0.0.1',
            'port' => 22,
            'username' => 'testuser',
            'password' => 'testpass',
            'log_path' => '/var/log/laravel.log',
        ];
        $configEncrypted = Crypt::encryptString(json_encode($config));

        $source = LogSource::create([
            'type' => 'ssh_pull',
            'config_encrypted' => $configEncrypted,
            'status' => 'active',
        ]);

        // Assert config is encrypted in DB
        $rawConfig = DB::select("SELECT config_encrypted FROM tenant_ssh_client.log_sources WHERE id = ?", [$source->id])[0]->config_encrypted;
        $this->assertNotEquals(json_encode($config), $rawConfig);
        $this->assertEquals(json_encode($config), Crypt::decryptString($rawConfig));

        // 2. Mock SFTP using SftpClientFactory container binding
        $sftpMock = \Mockery::mock(\phpseclib3\Net\SFTP::class);
        $sftpMock->shouldReceive('login')->with('testuser', 'testpass')->once()->andReturn(true);
        $sftpMock->shouldReceive('file_exists')->with('/var/log/laravel.log')->once()->andReturn(true);
        
        // Stack trace lines prefixed with # to parse correctly
        $dummyLogContent = "[2026-06-22 10:00:00] local.ERROR: Database connection failed {\"context\":\"value\"}\n#0 Stack trace line 1\n#1 Stack trace line 2\n[2026-06-22 10:01:00] local.WARNING: Query slow\nRaw unparsed line here\n";
        $sftpMock->shouldReceive('get')->with('/var/log/laravel.log')->once()->andReturn($dummyLogContent);

        $this->mock(\App\Services\SftpClientFactory::class, function ($mock) use ($sftpMock) {
            $mock->shouldReceive('make')->with('127.0.0.1', 22)->once()->andReturn($sftpMock);
        });

        // 3. Dispatch and execute the job
        $job = new \App\Jobs\PullLogsFromSSH($client->id, $source->id);
        $job->handle();

        // 4. Assert logs were correctly parsed and ingested
        $this->assertCount(3, LogEntry::all()); // 2 parsed, 1 unparsed

        $parsedLogs = LogEntry::where('is_parsed', true)->get();
        $this->assertCount(2, $parsedLogs);
        $this->assertEquals('error', $parsedLogs[0]->level);
        $this->assertEquals('Database connection failed {"context":"value"}', $parsedLogs[0]->raw_message);
        $this->assertStringContainsString('Stack trace line 1', $parsedLogs[0]->stack_trace);

        $unparsedLogs = LogEntry::where('is_parsed', false)->get();
        $this->assertCount(1, $unparsedLogs);
        $this->assertEquals('Raw unparsed line here', $unparsedLogs[0]->raw_message);

        $this->tenantManager->resetToCentral();
    }
}
