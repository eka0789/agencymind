<?php

namespace App\Jobs;

use App\Models\Tenant\LogSource;
use App\Models\Tenant\LogEntry;
use App\Domain\Tenancy\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SFTP;
use Carbon\Carbon;

class PullLogsFromSSH implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJob;

    public $logSourceId;

    /**
     * Create a new job instance.
     */
    public function __construct($clientId, $logSourceId)
    {
        $this->clientId = $clientId;
        $this->logSourceId = $logSourceId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $logSource = LogSource::find($this->logSourceId);

        if (!$logSource || $logSource->type !== 'ssh_pull') {
            Log::warning("LogSource #{$this->logSourceId} not found or is not ssh_pull");
            return;
        }

        try {
            // 1. Decrypt configuration
            $config = json_decode(Crypt::decryptString($logSource->config_encrypted), true);
            
            $host = $config['host'] ?? null;
            $port = $config['port'] ?? 22;
            $username = $config['username'] ?? null;
            $password = $config['password'] ?? null;
            $privateKey = $config['private_key'] ?? null;
            $logPath = $config['log_path'] ?? null;

            if (!$host || !$username || !$logPath) {
                throw new \Exception("Incomplete SSH configuration.");
            }

            // 2. Connect via SFTP
            $sftp = app(\App\Services\SftpClientFactory::class)->make($host, $port);
            
            if ($privateKey) {
                // If using private key
                $key = \phpseclib3\Crypt\PublicKeyLoader::load($privateKey);
                if (!$sftp->login($username, $key)) {
                    throw new \Exception("SFTP login failed using private key.");
                }
            } else {
                // If using password
                if (!$sftp->login($username, $password)) {
                    throw new \Exception("SFTP login failed using password.");
                }
            }

            // 3. Read log file content
            if (!$sftp->file_exists($logPath)) {
                throw new \Exception("Log file not found at path: {$logPath}");
            }

            $content = $sftp->get($logPath);
            if ($content === false) {
                throw new \Exception("Failed to read log file at path: {$logPath}");
            }

            // 4. Parse log content
            $this->parseAndIngestLogs($content, $logSource);

            // Trigger rule engine evaluation
            try {
                $ruleEngine = new \App\Domain\LogIngest\RuleEngine();
                $client = \App\Models\Client::find($this->clientId);
                if ($client) {
                    $ruleEngine->evaluate($client);
                }
            } catch (\Exception $e) {
                Log::error("Failed to run RuleEngine in PullLogsFromSSH for client [{$this->clientId}]: " . $e->getMessage());
            }

            // 5. Update last polled timestamp
            $logSource->update([
                'last_polled_at' => now(),
                'status' => 'active',
            ]);

            Log::info("Successfully pulled and ingested logs from SSH for LogSource #{$this->logSourceId}");

        } catch (\Exception $e) {
            $logSource->update(['status' => 'error']);
            Log::error("Failed to pull logs from SSH for LogSource #{$this->logSourceId}: " . $e->getMessage());
            throw $e; // Rethrow to let queue system retry
        }
    }

    /**
     * Parse log content and save to database.
     */
    protected function parseAndIngestLogs(string $content, LogSource $logSource): void
    {
        // Simple line parser or Laravel log format parser
        // Laravel log regex pattern: /^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): (.*)/
        $lines = explode("\n", $content);
        
        $currentEntry = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Check if line matches Laravel log format starting line
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): (.*)/', $line, $matches)) {
                // If we have an accumulated entry, save it first
                if ($currentEntry) {
                    $this->saveLogEntry($currentEntry, $logSource);
                }

                $currentEntry = [
                    'timestamp' => Carbon::parse($matches[1]),
                    'environment' => $matches[2],
                    'level' => strtolower($matches[3]),
                    'raw_message' => $matches[4],
                    'stack_trace' => '',
                    'is_parsed' => true,
                ];
            } else {
                // Check if it is a stack trace line
                $isStackTraceLine = preg_match('/^(\s|#|at\s)/', $line) || str_starts_with($line, 'at ');

                if ($isStackTraceLine && $currentEntry) {
                    $currentEntry['stack_trace'] .= $line . "\n";
                } else {
                    // If we have an accumulated entry, save it first
                    if ($currentEntry) {
                        $this->saveLogEntry($currentEntry, $logSource);
                        $currentEntry = null;
                    }

                    // Save this line as a raw unparsed line
                    $this->saveLogEntry([
                        'timestamp' => now(),
                        'level' => 'info',
                        'raw_message' => $line,
                        'stack_trace' => null,
                        'is_parsed' => false,
                    ], $logSource);
                }
            }
        }

        // Save last entry
        if ($currentEntry) {
            $this->saveLogEntry($currentEntry, $logSource);
        }
    }

    protected function saveLogEntry(array $data, LogSource $logSource): void
    {
        LogEntry::create([
            'log_source_id' => $logSource->id,
            'timestamp' => $data['timestamp'],
            'level' => $data['level'],
            'source_type' => 'laravel',
            'raw_message' => $data['raw_message'],
            'stack_trace' => !empty($data['stack_trace']) ? trim($data['stack_trace']) : null,
            'is_parsed' => $data['is_parsed'],
        ]);
    }
}
