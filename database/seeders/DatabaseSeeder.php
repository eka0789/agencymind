<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Client;
use App\Models\ValidationProfile;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Seed users for each role
        $owner = User::factory()->create([
            'name' => 'Pak Budi (Owner)',
            'email' => 'budi@agencymind.com',
            'password' => Hash::make('password'),
            'role' => 'owner',
        ]);

        $leadDev = User::factory()->create([
            'name' => 'Mas Andi (Lead Dev)',
            'email' => 'andi@agencymind.com',
            'password' => Hash::make('password'),
            'role' => 'lead_dev',
        ]);

        $developer = User::factory()->create([
            'name' => 'Sari (Developer)',
            'email' => 'sari@agencymind.com',
            'password' => Hash::make('password'),
            'role' => 'developer',
        ]);

        $admin = User::factory()->create([
            'name' => 'Platform Admin',
            'email' => 'admin@agencymind.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        // 2. Seed a default validation profile (agency-wide default profile, client_id = null)
        $defaultProfile = ValidationProfile::create([
            'client_id' => null,
            'name' => 'SOP Validasi Agensi',
            'rules_json' => [
                'security' => [
                    'env_not_committed' => true,
                    'app_debug_false' => true,
                    'csp_header_present' => true,
                ],
                'performance' => [
                    'assets_minified' => true,
                    'database_indexes' => true,
                ],
            ],
        ]);

        // 3. Seed a pilot client
        $client = Client::create([
            'name' => 'Toko ABC',
            'slug' => 'toko-abc',
            'contact_email' => 'info@tokoabc.com',
            'schema_name' => 'tenant_toko_abc',
            'status' => 'active',
            'sop_validation_profile_id' => $defaultProfile->id,
        ]);

        // 4. Assign the validation profile to this client specifically as well
        $defaultProfile->update(['client_id' => $client->id]);

        // 5. Setup user-client access pivot
        // Let's grant Developer Sari access to Toko ABC client
        $developer->clients()->attach($client->id, ['access_level' => 'write']);
    }
}
