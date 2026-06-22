<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class LLMGatewayManager
{
    protected array $drivers = [];

    public function __construct()
    {
        $this->drivers['openai'] = new OpenAIGatewayService();
        $this->drivers['anthropic'] = new AnthropicGatewayService();
    }

    /**
     * Get a driver instance by name.
     */
    public function driver(string $name = null): LLMGatewayService
    {
        $name = $name ?: config('services.llm.default', 'openai');

        if (!isset($this->drivers[$name])) {
            throw new \InvalidArgumentException("LLM Driver [{$name}] is not supported.");
        }

        return $this->drivers[$name];
    }

    /**
     * Analyze a prompt with automatic fallback logic.
     */
    public function analyzeWithFallback(string $prompt, string $systemPrompt = '', array $options = []): string
    {
        $primaryName = config('services.llm.default', 'openai');
        $backupName = $primaryName === 'openai' ? 'anthropic' : 'openai';

        try {
            Log::info("LLMGatewayManager: Attempting analysis with primary provider: {$primaryName}");
            return $this->driver($primaryName)->analyze($prompt, $systemPrompt, $options);
        } catch (\Exception $e) {
            Log::warning("LLMGatewayManager: Primary provider {$primaryName} failed: " . $e->getMessage() . ". Falling back to: {$backupName}");
            
            try {
                return $this->driver($backupName)->analyze($prompt, $systemPrompt, $options);
            } catch (\Exception $backupException) {
                Log::error("LLMGatewayManager: Backup provider {$backupName} also failed: " . $backupException->getMessage());
                throw new \Exception("Both LLM providers failed. Primary: " . $e->getMessage() . " | Backup: " . $backupException->getMessage(), 0, $backupException);
            }
        }
    }
}
