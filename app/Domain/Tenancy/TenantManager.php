<?php

namespace App\Domain\Tenancy;

use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class TenantManager
{
    /**
     * Create database schema for the client and run tenant migrations.
     */
    public function createSchemaForClient(Client $client): void
    {
        $schemaName = $client->schema_name;

        // Verify that we are using PostgreSQL connection
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            Log::warning("TenantManager schema creation skipped. Driver is {$driver}, not pgsql.");
            return;
        }

        // Sanitize schema name to prevent SQL injection
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $schemaName)) {
            throw new \InvalidArgumentException("Invalid schema name: {$schemaName}");
        }

        // 1. Create the schema in PostgreSQL using the central connection
        DB::statement("CREATE SCHEMA IF NOT EXISTS {$schemaName}");

        // 2. Switch configuration to tenant schema and purge connection
        config(['database.connections.pgsql.search_path' => $schemaName]);
        DB::purge('pgsql');

        try {
            // 3. Run tenant migrations on reconnected connection
            Artisan::call('migrate', [
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);

            Log::info("Created schema and ran migrations for tenant: {$schemaName}");
        } finally {
            // 4. Restore configuration to public schema
            config(['database.connections.pgsql.search_path' => 'public']);
            DB::purge('pgsql');
        }
    }

    /**
     * Switch current connection context to the client's schema.
     */
    public function switchToTenant(Client $client): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            return;
        }

        $schemaName = $client->schema_name;
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $schemaName)) {
            throw new \InvalidArgumentException("Invalid schema name: {$schemaName}");
        }

        // Dynamically update PostgreSQL search_path in config and purge to enforce
        config(['database.connections.pgsql.search_path' => "{$schemaName},public"]);
        DB::purge('pgsql');
        DB::reconnect('pgsql');
    }

    /**
     * Reset database connection context back to public (central) schema.
     */
    public function resetToCentral(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            return;
        }

        config(['database.connections.pgsql.search_path' => 'public']);
        DB::purge('pgsql');
        DB::reconnect('pgsql');
    }
}
