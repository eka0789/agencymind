<?php

namespace App\Domain\ProjectValidation;

use App\Models\Client;
use App\Models\ValidationProfile;
use App\Models\Tenant\ValidationResult;
use App\Services\LLMGatewayManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ValidationEngine
{
    protected $llmManager;

    public function __construct(LLMGatewayManager $llmManager)
    {
        $this->llmManager = $llmManager;
    }

    /**
     * Run validation for a client using their validation profile rules.
     */
    public function validate(Client $client, string $projectPath, int $triggeredByUserId): ValidationResult
    {
        // 1. Get validation profile
        $profile = ValidationProfile::where('client_id', $client->id)->first()
            ?: ValidationProfile::whereNull('client_id')->first();

        if (!$profile) {
            throw new \Exception("No validation profile found.");
        }

        $rules = $profile->rules_json;

        // 2. Perform rule-based checklist
        $checklist = $this->runRuleBasedChecks($projectPath, $rules);

        // 3. Compile context for qualitative LLM assessment
        $summary = $this->generateProjectSummary($projectPath, $checklist);

        // 4. Run qualitative review via LLM (Validation Agent)
        $aiReview = $this->runQualitativeReview($client, $summary);

        // 5. Merge results & determine overall status
        $results = array_merge($checklist, [
            'ai_qualitative_assessment' => $aiReview['assessment'],
            'architectural_notes' => $aiReview['notes'],
        ]);

        // Blocker fails -> fail, Major fails -> fail, minor fails -> pass with notes
        $overallStatus = 'pass';
        $hasBlockerFail = false;
        $hasMajorFail = false;

        foreach ($results['categories'] as $category => $items) {
            foreach ($items as $item) {
                if ($item['status'] === 'fail') {
                    if ($item['severity'] === 'blocker') {
                        $hasBlockerFail = true;
                    } elseif ($item['severity'] === 'major') {
                        $hasMajorFail = true;
                    }
                }
            }
        }

        if ($hasBlockerFail) {
            $overallStatus = 'fail';
        } elseif ($hasMajorFail) {
            $overallStatus = 'fail';
        } else {
            // Check if there are any warnings/notes
            $hasWarning = false;
            foreach ($results['categories'] as $category => $items) {
                foreach ($items as $item) {
                    if ($item['status'] === 'warning' || $item['status'] === 'fail') {
                        $hasWarning = true;
                    }
                }
            }
            if ($hasWarning || !empty($aiReview['notes'])) {
                $overallStatus = 'pass_with_notes';
            }
        }

        // 6. Save results to database
        $validationResult = ValidationResult::create([
            'validation_profile_id' => $profile->id,
            'triggered_by_user_id' => $triggeredByUserId,
            'overall_status' => $overallStatus,
            'results_json' => $results,
        ]);

        return $validationResult;
    }

    protected function runRuleBasedChecks(string $path, array $rules): array
    {
        $categories = [
            'security' => [],
            'performance' => [],
            'documentation' => [],
        ];

        if (!File::isDirectory($path)) {
            // If the path doesn't exist, mark all checks as failed or skipped
            $categories['security'][] = [
                'name' => 'Project directory exists',
                'status' => 'fail',
                'severity' => 'blocker',
                'message' => 'Directory path is invalid or inaccessible: ' . $path,
            ];
            return ['categories' => $categories];
        }

        // --- SECURITY CHECKS ---
        
        // Check 1: committed .env file
        $dotEnvCommitted = File::exists($path . '/.env');
        $gitIgnoreExists = File::exists($path . '/.gitignore');
        $gitIgnoreMuted = false;
        if ($gitIgnoreExists) {
            $content = File::get($path . '/.gitignore');
            if (str_contains($content, '.env')) {
                $gitIgnoreMuted = true;
            }
        }
        $categories['security'][] = [
            'name' => 'No committed .env files',
            'status' => ($dotEnvCommitted && !$gitIgnoreMuted) ? 'fail' : 'pass',
            'severity' => 'blocker',
            'message' => ($dotEnvCommitted && !$gitIgnoreMuted) ? '.env file is present and not ignored in .gitignore.' : 'No untracked .env file vulnerability detected.',
        ];

        // Check 2: APP_DEBUG status
        $appDebugTrue = false;
        if ($dotEnvCommitted) {
            $envContent = File::get($path . '/.env');
            if (preg_match('/APP_DEBUG\s*=\s*true/i', $envContent)) {
                $appDebugTrue = true;
            }
        }
        $categories['security'][] = [
            'name' => 'APP_DEBUG set to false',
            'status' => $appDebugTrue ? 'fail' : 'pass',
            'severity' => 'blocker',
            'message' => $appDebugTrue ? 'APP_DEBUG=true is set in .env file.' : 'APP_DEBUG is set to false (or env not present).',
        ];

        // Check 3: Hardcoded credentials scan
        $hardcodedFound = false;
        $foundDetails = [];
        
        // Scan PHP and JS files recursively in the project
        $filesToScan = [];
        if (File::isDirectory($path)) {
            $files = File::allFiles($path);
            foreach ($files as $file) {
                $filePath = $file->getRealPath();
                if (str_contains($filePath, 'vendor') || str_contains($filePath, 'node_modules')) {
                    continue;
                }
                $ext = $file->getExtension();
                if ($ext === 'php' || $ext === 'js') {
                    $filesToScan[] = $filePath;
                }
            }
        }

        foreach (array_slice($filesToScan, 0, 100) as $file) {
            $content = File::get($file);
            if (preg_match('/(api_key|password|client_secret|aws_secret)\s*=\s*[\'"][a-zA-Z0-9_\-]{8,}[\'"]/i', $content, $matches)) {
                $hardcodedFound = true;
                $basename = basename($file);
                $foundDetails[] = "Found pattern match in {$basename}: " . $matches[0];
            }
        }

        $categories['security'][] = [
            'name' => 'No hardcoded credentials',
            'status' => $hardcodedFound ? 'fail' : 'pass',
            'severity' => 'major',
            'message' => $hardcodedFound ? implode(', ', $foundDetails) : 'No hardcoded credential patterns found.',
        ];

        // Check 4: Security headers config
        $headersDefined = false;
        foreach ($filesToScan as $file) {
            $content = File::get($file);
            if (str_contains($content, 'Content-Security-Policy') || str_contains($content, 'X-Frame-Options')) {
                $headersDefined = true;
                break;
            }
        }
        $categories['security'][] = [
            'name' => 'Basic security headers config',
            'status' => $headersDefined ? 'pass' : 'warning',
            'severity' => 'minor',
            'message' => $headersDefined ? 'Content-Security-Policy or X-Frame-Options headers are defined.' : 'Security headers (CSP/X-Frame-Options) not explicitly found in scanned files.',
        ];

        // --- PERFORMANCE CHECKS ---
        
        // Check 1: Minified assets
        $minifiedAssets = false;
        $publicFiles = File::glob($path . '/public/**/*');
        foreach ($publicFiles as $file) {
            if (str_contains($file, '.min.js') || str_contains($file, '.min.css')) {
                $minifiedAssets = true;
                break;
            }
        }
        if (!$minifiedAssets && File::exists($path . '/package.json')) {
            $pkg = json_decode(File::get($path . '/package.json'), true);
            if (isset($pkg['scripts']['build'])) {
                $minifiedAssets = true;
            }
        }
        $categories['performance'][] = [
            'name' => 'Minified assets build setup',
            'status' => $minifiedAssets ? 'pass' : 'warning',
            'severity' => 'minor',
            'message' => $minifiedAssets ? 'Minified assets or build step detected.' : 'No minified assets (.min.js/css) or build setup found.',
        ];

        // --- DOCUMENTATION CHECKS ---
        
        // Check 1: README.md exists
        $readmeExists = File::exists($path . '/README.md') || File::exists($path . '/readme.md');
        $categories['documentation'][] = [
            'name' => 'README.md exists',
            'status' => $readmeExists ? 'pass' : 'fail',
            'severity' => 'major',
            'message' => $readmeExists ? 'README.md exists.' : 'README.md is missing from project root.',
        ];

        // Check 2: .env.example exists
        $envExampleExists = File::exists($path . '/.env.example');
        $categories['documentation'][] = [
            'name' => '.env.example exists',
            'status' => $envExampleExists ? 'pass' : 'warning',
            'severity' => 'minor',
            'message' => $envExampleExists ? '.env.example exists.' : '.env.example is missing from project root.',
        ];

        return ['categories' => $categories];
    }

    protected function generateProjectSummary(string $path, array $checklist): string
    {
        $summary = "Project Path: {$path}\n\n=== RULE-BASED CHECKLIST ===\n";
        foreach ($checklist['categories'] as $category => $items) {
            $summary .= strtoupper($category) . ":\n";
            foreach ($items as $item) {
                $summary .= "- [{$item['status']}] {$item['name']} (Severity: {$item['severity']}): {$item['message']}\n";
            }
        }

        if (File::isDirectory($path)) {
            $files = File::files($path);
            $directories = File::directories($path);
            
            $summary .= "\n=== PROJECT STRUCTURE ===\n";
            $summary .= "Directories:\n";
            foreach ($directories as $dir) {
                $summary .= "  - " . basename($dir) . "\n";
            }
            $summary .= "Files:\n";
            foreach ($files as $file) {
                $summary .= "  - " . basename($file) . "\n";
            }
        }

        return $summary;
    }

    protected function runQualitativeReview(Client $client, string $summary): array
    {
        $systemPrompt = <<<PROMPT
You are the Project Validation Agent for AgencyMind. Your job is to perform a qualitative/architectural code validation on the project context provided.
Review the files, rule-based checklist results, and project structure. Give architectural comments and list any qualitative issues (e.g. non-standard folder layout, missing typical framework configurations).

You MUST respond ONLY with a valid JSON object matching the following structure:
{
  "assessment": "Excellent project layout and architecture." | "Standard project layout with minor observations." | "Poor project structure or architectural violations.",
  "notes": "Provide 1-3 bullet points of qualitative observations or recommendations."
}
PROMPT;

        try {
            $response = $this->llmManager->analyzeWithFallback(
                "Client: {$client->name}\n\n=== SUMMARY ===\n{$summary}",
                $systemPrompt
            );

            $cleanText = trim($response);
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
                Log::warning("ValidationEngine: Failed to parse LLM qualitative assessment JSON. Raw: {$response}");
                return [
                    'assessment' => 'Standard project layout with minor observations.',
                    'notes' => 'AI feedback parsing failed. Manual review recommended.',
                ];
            }

            return [
                'assessment' => $decoded['assessment'] ?? 'Standard project layout with minor observations.',
                'notes' => $decoded['notes'] ?? '',
            ];
        } catch (\Exception $e) {
            Log::error("ValidationEngine: LLM qualitative review failed: " . $e->getMessage());
            return [
                'assessment' => 'Standard project layout with minor observations.',
                'notes' => 'LLM evaluation service unavailable. Rule-based checks are complete.',
            ];
        }
    }
}
