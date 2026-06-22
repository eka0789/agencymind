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

        return $response->json('choices.0.message.content') ?? '';
    }
}
