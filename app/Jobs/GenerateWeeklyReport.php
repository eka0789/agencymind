<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Tenant\Incident;
use App\Models\Tenant\ValidationResult;
use App\Models\Tenant\WeeklyReport;
use App\Domain\Tenancy\TenantAwareJob;
use App\Services\LLMGatewayManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class GenerateWeeklyReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJob;

    public string $jobId;
    public string $discordChannelId;
    public string $discordUserId;
    public int $days;

    /**
     * Create a new job instance.
     */
    public function __construct(
        int $clientId,
        string $jobId,
        string $discordChannelId,
        string $discordUserId,
        int $days = 7
    ) {
        $this->clientId = $clientId;
        $this->jobId = $jobId;
        $this->discordChannelId = $discordChannelId;
        $this->discordUserId = $discordUserId;
        $this->days = $days;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $client = Client::find($this->clientId);
        if (!$client) {
            Log::warning("GenerateWeeklyReport: Client #{$this->clientId} not found.");
            return;
        }

        try {
            $periodEnd = Carbon::now();
            $periodStart = Carbon::now()->subDays($this->days);

            // Fetch incidents
            $incidents = Incident::where('created_at', '>=', $periodStart)
                ->orderBy('created_at', 'asc')
                ->get();

            // Fetch validation results
            $validations = ValidationResult::where('created_at', '>=', $periodStart)
                ->orderBy('created_at', 'asc')
                ->get();

            // Format data for LLM
            $dataForLLM = "Periode Laporan: {$periodStart->toDateString()} hingga {$periodEnd->toDateString()}\n\n";
            $dataForLLM .= "=== DAFTAR INSIDEN ===\n";
            if ($incidents->isEmpty()) {
                $dataForLLM .= "Tidak ada insiden yang tercatat pada periode ini.\n";
            } else {
                foreach ($incidents as $inc) {
                    $dataForLLM .= "- ID: {$inc->id} | Severity: {$inc->severity} | Status: {$inc->status}\n";
                    $dataForLLM .= "  Judul: {$inc->title}\n";
                    $dataForLLM .= "  RCA Summary: {$inc->rca_summary}\n\n";
                }
            }

            $dataForLLM .= "=== HASIL VALIDASI PROYEK ===\n";
            if ($validations->isEmpty()) {
                $dataForLLM .= "Tidak ada validasi proyek yang dijalankan pada periode ini.\n";
            } else {
                foreach ($validations as $val) {
                    $dataForLLM .= "- ID: {$val->id} | Overall Status: {$val->overall_status} | Waktu: {$val->created_at}\n";
                }
            }

            // Call LLM
            /** @var LLMGatewayManager $llmManager */
            $llmManager = app(LLMGatewayManager::class);
            $systemPrompt = "Anda adalah Reporting Agent untuk AgencyMind. Buatkan draf laporan mingguan profesional (dalam Markdown) untuk dikirim ke klien '{$client->name}'. Laporan harus terstruktur, rapi, dan mudah dibaca oleh non-technical stakeholder, dengan merangkum insiden dan hasil validasi yang terjadi. Jangan mengarang data tambahan.";
            
            $draftContent = $llmManager->analyzeWithFallback(
                $dataForLLM,
                $systemPrompt,
                [
                    'client_id' => $this->clientId,
                    'task_type' => 'weekly_report',
                ]
            );

            // Save to WeeklyReport
            $report = WeeklyReport::create([
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'draft_content' => $draftContent,
                'status' => 'draft'
            ]);

            // Webhook callback
            $webhookUrl = config('services.discord.webhook_url');
            if ($webhookUrl) {
                Http::withHeaders([
                    'Authorization' => 'Bearer ' . config('services.discord.api_token')
                ])->post($webhookUrl, [
                    'job_id' => $this->jobId,
                    'type' => 'report_generated',
                    'discord_channel_id' => $this->discordChannelId,
                    'discord_user_id' => $this->discordUserId,
                    'client_slug' => $client->slug,
                    'result' => [
                        'report_id' => $report->id,
                        'period' => "{$periodStart->toDateString()} s/d {$periodEnd->toDateString()}",
                        'summary' => "Draf laporan mingguan telah siap. Gunakan perintah `/report-approve {$client->slug} {$report->id}` untuk menyetujui dan mengirimnya.\n\n" . substr($draftContent, 0, 1500) . (strlen($draftContent) > 1500 ? '...' : ''),
                    ]
                ]);
            }

        } catch (\Exception $e) {
            Log::error("GenerateWeeklyReport: Job #{$this->jobId} failed: " . $e->getMessage());
            $webhookUrl = config('services.discord.webhook_url');
            if ($webhookUrl) {
                Http::withHeaders([
                    'Authorization' => 'Bearer ' . config('services.discord.api_token')
                ])->post($webhookUrl, [
                    'job_id' => $this->jobId,
                    'type' => 'report_generated',
                    'discord_channel_id' => $this->discordChannelId,
                    'discord_user_id' => $this->discordUserId,
                    'client_slug' => $client->slug,
                    'error' => 'Generate Weekly Report failed: ' . $e->getMessage(),
                ]);
            }
            throw $e;
        }
    }
}
