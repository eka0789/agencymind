<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;
use Livewire\Livewire;
use App\Livewire\Pulse\ClientLogsCard;

class PulseCardTest extends TestCase
{
    use DatabaseMigrations;

    public function test_pulse_card_renders_successfully()
    {
        \Livewire\Livewire::withoutLazyLoading();

        Client::create([
            'name' => 'Test Client A',
            'slug' => 'test-client-a',
            'contact_email' => 'a@test.com',
            'status' => 'active',
        ]);

        Livewire::test(ClientLogsCard::class)
            ->assertStatus(200)
            ->assertSee('Test Client A')
            ->assertSee('test-client-a');
    }

    public function test_llm_cost_card_renders_successfully()
    {
        \Livewire\Livewire::withoutLazyLoading();

        $client = Client::create([
            'name' => 'Test Client B',
            'slug' => 'test-client-b',
            'contact_email' => 'b@test.com',
            'status' => 'active',
        ]);

        \App\Models\LlmUsage::create([
            'client_id' => $client->id,
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'task_type' => 'log_check',
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'estimated_cost_usd' => 0.00125,
        ]);

        Livewire::test(\App\Livewire\Pulse\LlmCostCard::class)
            ->assertStatus(200)
            ->assertSee('Test Client B')
            ->assertSee('0.0013'); // rounded formatted cost or the value
    }
}
