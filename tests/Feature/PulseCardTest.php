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
}
