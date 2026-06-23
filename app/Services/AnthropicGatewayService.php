<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnthropicGatewayService implements LLMGatewayService
{
    protected string $apiKey;
    protected string $defaultModel;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.key') ?: '';
        $this->defaultModel = config('services.anthropic.model') ?: 'claude-3-5-sonnet-20240620';
    }

    /**
     * Call Anthropic Messages API.
     */
    public function analyze(string $prompt, string $systemPrompt = '', array $options = []): string
    {
        if (empty($this->apiKey)) {
            Log::error('Anthropic API Key is not configured.');
            throw new \Exception('Anthropic API Key is not configured.');
        }

        $model = $options['model'] ?? $this->defaultModel;
        $temperature = $options['temperature'] ?? 0.2;
        $maxTokens = $options['max_tokens'] ?? 2000;

        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ]
            ],
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
        ];

        // Anthropic system prompt is passed as a top-level property
        if (!empty($systemPrompt)) {
            $payload['system'] = $systemPrompt;
        }

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])
        ->timeout($options['timeout'] ?? 30)
        ->post('https://api.anthropic.com/v1/messages', $payload);

        if ($response->failed()) {
            $errorMsg = $response->json('error.message') ?? $response->body();
            Log::error('Anthropic API Call failed: ' . $errorMsg);
            throw new \Exception('Anthropic API call failed: ' . $errorMsg);
        }

        // Extract usage
        $promptTokens = $response->json('usage.input_tokens') ?? 0;
        $completionTokens = $response->json('usage.output_tokens') ?? 0;
        
        // Estimate cost for claude-3-5-sonnet ($3.00/1M input, $15.00/1M output)
        $cost = ($promptTokens / 1000000 * 3.0) + ($completionTokens / 1000000 * 15.0);

        try {
            \App\Models\LlmUsage::create([
                'client_id' => $options['client_id'] ?? null,
                'provider' => 'anthropic',
                'model' => $model,
                'task_type' => $options['task_type'] ?? null,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'estimated_cost_usd' => $cost,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log LLM usage: ' . $e->getMessage());
        }

        return $response->json('content.0.text') ?? '';
    }
}
