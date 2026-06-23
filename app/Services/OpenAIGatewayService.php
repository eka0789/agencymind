<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIGatewayService implements LLMGatewayService
{
    protected string $apiKey;
    protected string $defaultModel;

    public function __construct()
    {
        $this->apiKey = config('services.openai.key') ?: '';
        $this->defaultModel = config('services.openai.model') ?: 'gpt-4o';
    }

    /**
     * Call OpenAI Chat Completion API.
     */
    public function analyze(string $prompt, string $systemPrompt = '', array $options = []): string
    {
        if (empty($this->apiKey)) {
            Log::error('OpenAI API Key is not configured.');
            throw new \Exception('OpenAI API Key is not configured.');
        }

        $model = $options['model'] ?? $this->defaultModel;
        $temperature = $options['temperature'] ?? 0.2;
        $maxTokens = $options['max_tokens'] ?? 2000;

        $messages = [];
        if (!empty($systemPrompt)) {
            $messages[] = [
                'role' => 'system',
                'content' => $systemPrompt,
            ];
        }
        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])
        ->timeout($options['timeout'] ?? 30)
        ->post('https://api.openai.com/v1/chat/completions', [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ]);

        if ($response->failed()) {
            $errorMsg = $response->json('error.message') ?? $response->body();
            Log::error('OpenAI API Call failed: ' . $errorMsg);
            throw new \Exception('OpenAI API call failed: ' . $errorMsg);
        }

        // Extract usage
        $promptTokens = $response->json('usage.prompt_tokens') ?? 0;
        $completionTokens = $response->json('usage.completion_tokens') ?? 0;
        
        // Estimate cost for gpt-4o ($5.00/1M input, $15.00/1M output)
        $cost = ($promptTokens / 1000000 * 5.0) + ($completionTokens / 1000000 * 15.0);

        try {
            \App\Models\LlmUsage::create([
                'client_id' => $options['client_id'] ?? null,
                'provider' => 'openai',
                'model' => $model,
                'task_type' => $options['task_type'] ?? null,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'estimated_cost_usd' => $cost,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log LLM usage: ' . $e->getMessage());
        }

        return $response->json('choices.0.message.content') ?? '';
    }
}
