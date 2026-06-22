<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Tenant\Incident;
use App\Models\Tenant\LogEntry;
use App\Domain\Tenancy\TenantAwareJob;
use App\Services\LLMGatewayManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeIncidentRCA implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJob;

    public int $incidentId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $clientId, int $incidentId)
    {
        $this->clientId = $clientId;
        $this->incidentId = $incidentId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $incident = Incident::find($this->incidentId);

        if (!$incident) {
            Log::warning("AnalyzeIncidentRCA: Incident #{$this->incidentId} not found.");
            return;
        }

        if ($incident->status === 'resolved' || !empty($incident->rca_summary)) {
            Log::info("AnalyzeIncidentRCA: Incident #{$this->incidentId} is already resolved or has RCA summary.");
            return;
        }

        $client = Client::find($this->clientId);
        if (!$client) {
            Log::warning("AnalyzeIncidentRCA: Client #{$this->clientId} not found.");
            return;
        }

        // 1. Gather context logs
        $relatedIds = $incident->related_log_entry_ids ?? [];
        if (empty($relatedIds)) {
            Log::warning("AnalyzeIncidentRCA: No related log entry IDs found for incident #{$this->incidentId}.");
            return;
        }

        $logEntries = LogEntry::whereIn('id', $relatedIds)->orderBy('timestamp', 'asc')->get();

        // 2. Format and scrub log entries (sensor sensitive data)
        $formattedLogs = '';
        foreach ($logEntries as $entry) {
            $raw = "ID: {$entry->id} | Time: {$entry->timestamp} | Level: {$entry->level} | Source: {$entry->source_type} | HTTP: {$entry->http_status}\nMessage: {$entry->raw_message}\n";
            if (!empty($entry->stack_trace)) {
                $raw .= "Stack Trace:\n{$entry->stack_trace}\n";
            }
            $raw .= "----------------------------------------\n";
            $formattedLogs .= $this->scrubSensitiveData($raw);
        }

        // 3. Prepare Prompt
        $systemPrompt = $this->getSystemPrompt($client);
        $userPrompt = "Incident Title: {$incident->title}\nSeverity: {$incident->severity}\n\n=== RELEVANT LOG ENTRIES ===\n{$formattedLogs}\nPlease analyze the root cause of this incident and output your analysis in the required JSON format.";

        // 4. Call LLM Gateway
        try {
            /** @var LLMGatewayManager $llmManager */
            $llmManager = app(LLMGatewayManager::class);
            $response = $llmManager->analyzeWithFallback($userPrompt, $systemPrompt);

            // 5. Parse response and update database
            $parsed = $this->parseResponse($response);

            $incident->update([
                'rca_summary' => $parsed['summary'],
                'rca_confidence' => $parsed['confidence'],
            ]);

            Log::info("AnalyzeIncidentRCA: Successfully saved RCA for Incident #{$this->incidentId}");

        } catch (\Exception $e) {
            Log::error("AnalyzeIncidentRCA: Failed to generate RCA for Incident #{$this->incidentId}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Scrub sensitive information from logs before sending to external LLM providers.
     */
    protected function scrubSensitiveData(string $text): string
    {
        // 1. Scrub emails
        $text = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[EMAIL_REDACTED]', $text);

        // 2. Scrub IP addresses (v4)
        $text = preg_replace('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', '[IP_REDACTED]', $text);

        // 3. Scrub secrets, passwords, auth tokens
        $text = preg_replace('/(password|pass|secret|token|key|api_key|auth_token)\s*=\s*[^\s&"]+/', '$1=[REDACTED]', $text);
        $text = preg_replace('/"(password|pass|secret|token|key|api_key|auth_token)"\s*:\s*"[^"]*"/', '"$1":"[REDACTED]"', $text);

        return $text;
    }

    /**
     * Formulate the Log Analyst Agent's system prompt.
     */
    protected function getSystemPrompt(Client $client): string
    {
        return <<<PROMPT
You are the Log Analyst Agent for AgencyMind, a digital agency platform. Your task is to perform an objective, technical Root Cause Analysis (RCA) based strictly on the provided server logs for the client: "{$client->name}".

Guidelines:
1. **Analyze Reentrancy and Root Cause**: Study the timestamps, exception messages, status codes, and stack traces. Find the primary underlying failure.
2. **Be Objective and Factual**: Do not guess or hallucinate. Use ONLY the facts provided in the logs.
3. **Reference Evidence**: Support your claims by referencing specific log IDs (e.g., Log ID 23).
4. **Determine Confidence Level**:
   - "high": The error message and context unambiguously point to a specific cause (e.g., database connection credentials incorrect).
   - "medium": The issue is identified, but some details might be missing or there are multiple possible causes.
   - "low": The logs are insufficient, corrupted, or too generic to verify the root cause.
5. **No Destructive/Self-healing Action**: Do not propose code fixes that modify files without review. Focus on identifying and recommending steps.

You MUST respond ONLY with a valid JSON object matching the following structure:
{
  "confidence": "high" | "medium" | "low",
  "summary": "## Root Cause Analysis Summary\\n\\n### 1. Masalah Utama\\n[Description of the issue]\\n\\n### 2. Bukti Teknis\\n[Link to evidence using Log IDs]\\n\\n### 3. Rekomendasi Resolusi\\n[Step-by-step advice]"
}
PROMPT;
    }

    /**
     * Clean and parse the LLM's JSON response.
     */
    protected function parseResponse(string $response): array
    {
        $cleanText = trim($response);

        // Strip markdown code blocks if the model wrapped the JSON
        if (str_starts_with($cleanText, '```json')) {
            $cleanText = substr($cleanText, 7);
        } elseif (str_starts_with($cleanText, '```')) {
            $cleanText = substr($cleanText, 3);
        }

        if (str_ends_with($cleanText, '```')) {
            $cleanText = substr($cleanText, 0, -3);
        }

        $cleanText = trim($cleanText);
        $decoded = json_decode($cleanText, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning("AnalyzeIncidentRCA: Failed to parse JSON response. Falling back to raw response as summary. Raw: {$response}");
            return [
                'confidence' => 'low',
                'summary' => "## Analysis Results (Raw Output)\n\n" . $response,
            ];
        }

        return [
            'confidence' => strtolower($decoded['confidence'] ?? 'medium'),
            'summary' => $decoded['summary'] ?? $response,
        ];
    }
}
