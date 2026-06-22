<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AuthAndRbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_gates_restrict_unauthorized_users()
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $leadDev = User::factory()->create(['role' => 'lead_dev']);
        $admin = User::factory()->create(['role' => 'admin']);
        $developer = User::factory()->create(['role' => 'developer']);

        // Test override-validation gate
        $this->assertTrue(Gate::forUser($owner)->allows('override-validation'));
        $this->assertTrue(Gate::forUser($leadDev)->allows('override-validation'));
        $this->assertTrue(Gate::forUser($admin)->allows('override-validation'));
        $this->assertFalse(Gate::forUser($developer)->allows('override-validation'));

        // Test manage-thresholds gate
        $this->assertTrue(Gate::forUser($owner)->allows('manage-thresholds'));
        $this->assertTrue(Gate::forUser($leadDev)->allows('manage-thresholds'));
        $this->assertTrue(Gate::forUser($admin)->allows('manage-thresholds'));
        $this->assertFalse(Gate::forUser($developer)->allows('manage-thresholds'));
    }

    public function test_user_client_access_control()
    {
        $clientA = Client::create([
            'name' => 'Client A',
            'slug' => 'client-a',
            'contact_email' => 'a@test.com',
            'status' => 'active',
        ]);

        $clientB = Client::create([
            'name' => 'Client B',
            'slug' => 'client-b',
            'contact_email' => 'b@test.com',
            'status' => 'active',
        ]);

        // Create developer
        $developer = User::factory()->create(['role' => 'developer']);
        // Grant developer access to Client A
        $developer->clients()->attach($clientA->id, ['access_level' => 'write']);

        // Create admin and owner
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'owner']);

        // Assert developer has access only to Client A
        $this->assertTrue($developer->hasAccessToClient($clientA));
        $this->assertFalse($developer->hasAccessToClient($clientB));

        // Assert admin and owner have access to both
        $this->assertTrue($admin->hasAccessToClient($clientA));
        $this->assertTrue($admin->hasAccessToClient($clientB));
        $this->assertTrue($owner->hasAccessToClient($clientA));
        $this->assertTrue($owner->hasAccessToClient($clientB));
    }

    public function test_middleware_authenticates_and_isolates_schema()
    {
        $clientA = Client::create([
            'name' => 'Client A',
            'slug' => 'client-a',
            'contact_email' => 'a@test.com',
            'status' => 'active',
        ]);

        $clientB = Client::create([
            'name' => 'Client B',
            'slug' => 'client-b',
            'contact_email' => 'b@test.com',
            'status' => 'active',
        ]);

        $developer = User::factory()->create(['role' => 'developer']);
        $developer->clients()->attach($clientA->id, ['access_level' => 'write']);

        // 1. Unauthenticated gets 401
        $response = $this->getJson("/api/v1/clients/{$clientA->slug}/status");
        $response->assertStatus(401);

        // 2. Authenticated but forbidden client access gets 403
        $response = $this->actingAs($developer, 'sanctum')
            ->getJson("/api/v1/clients/{$clientB->slug}/status");
        $response->assertStatus(403);

        // 3. Authenticated and authorized client access gets 200 and switches schema
        $response = $this->actingAs($developer, 'sanctum')
            ->getJson("/api/v1/clients/{$clientA->slug}/status");
        
        $response->assertStatus(200);
        $response->assertJson([
            'client' => 'client-a',
            'schema' => 'tenant_client_a, public',
        ]);
    }
}
