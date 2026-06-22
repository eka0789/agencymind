<?php

namespace App\Services;

interface LLMGatewayService
{
    /**
     * Call LLM provider to analyze prompt.
     *
     * @param string $prompt
     * @param string $systemPrompt
     * @param array $options
     * @return string
     */
    public function analyze(string $prompt, string $systemPrompt = '', array $options = []): string;
}
