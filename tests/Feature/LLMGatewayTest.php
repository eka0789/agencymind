<?php

namespace Tests\Feature;

use App\Services\LLMGatewayManager;
use App\Services\OpenAIGatewayService;
use App\Services\AnthropicGatewayService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LLMGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Setup dummy keys for tests
        config(['services.openai.key' => 'test-openai-key']);
        config(['services.anthropic.key' => 'test-anthropic-key']);
    }

    public function test_openai_gateway_sends_correct_payload()
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'OpenAI analysis result'
                        ]
                    ]
                ]
            ], 200)
        ]);

        $service = new OpenAIGatewayService();
        $result = $service->analyze('Check log', 'You are analyst');

        $this->assertEquals('OpenAI analysis result', $result);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.openai.com/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-openai-key')
                && $request['model'] === 'gpt-4o'
                && $request['messages'][0]['role'] === 'system'
                && $request['messages'][0]['content'] === 'You are analyst'
                && $request['messages'][1]['role'] === 'user'
                && $request['messages'][1]['content'] === 'Check log';
        });
    }

    public function test_anthropic_gateway_sends_correct_payload()
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    [
                        'text' => 'Anthropic analysis result'
                    ]
                ]
            ], 200)
        ]);

        $service = new AnthropicGatewayService();
        $result = $service->analyze('Check log', 'You are analyst');

        $this->assertEquals('Anthropic analysis result', $result);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key', 'test-anthropic-key')
                && $request['model'] === 'claude-3-5-sonnet-20240620'
                && $request['system'] === 'You are analyst'
                && $request['messages'][0]['role'] === 'user'
                && $request['messages'][0]['content'] === 'Check log';
        });
    }

    public function test_manager_resolves_default_driver()
    {
        config(['services.llm.default' => 'anthropic']);
        $manager = new LLMGatewayManager();
        $this->assertInstanceOf(AnthropicGatewayService::class, $manager->driver());

        config(['services.llm.default' => 'openai']);
        $manager = new LLMGatewayManager();
        $this->assertInstanceOf(OpenAIGatewayService::class, $manager->driver());
    }

    public function test_manager_falls_back_when_primary_fails()
    {
        config(['services.llm.default' => 'openai']);

        // Mock OpenAI to fail, but Anthropic to succeed
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(['error' => 'Rate limit'], 429),
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [['text' => 'Fallback result']]
            ], 200)
        ]);

        $manager = new LLMGatewayManager();
        $result = $manager->analyzeWithFallback('Check log', 'System instructions');

        $this->assertEquals('Fallback result', $result);

        // Verify both endpoint requests were dispatched
        Http::assertSentCount(2);
    }

    public function test_manager_throws_exception_when_both_fail()
    {
        config(['services.llm.default' => 'openai']);

        // Mock both to fail
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([], 500),
            'https://api.anthropic.com/v1/messages' => Http::response([], 500)
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Both LLM providers failed');

        $manager = new LLMGatewayManager();
        $manager->analyzeWithFallback('Check log');
    }
}
