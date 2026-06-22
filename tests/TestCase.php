<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clean up any dynamic tenant schemas from previous tests/runs
        if (\Illuminate\Support\Facades\Schema::getConnection()->getDriverName() === 'pgsql') {
            $schemas = \Illuminate\Support\Facades\DB::select("SELECT nspname FROM pg_namespace WHERE nspname LIKE 'tenant_%'");
            foreach ($schemas as $schema) {
                \Illuminate\Support\Facades\DB::statement("DROP SCHEMA IF EXISTS {$schema->nspname} CASCADE");
            }
        }
    }
}
