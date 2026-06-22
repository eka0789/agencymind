<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Exception;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_logs_can_be_created_but_not_updated_or_deleted()
    {
        // 1. Create a user
        $user = User::factory()->create([
            'role' => 'admin',
        ]);

        // 2. Create audit log
        $log = AuditLog::create([
            'user_id' => $user->id,
            'action_type' => 'test_action',
            'command_text' => 'command text',
            'payload_json' => ['param' => 'value'],
            'result_summary' => 'success',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'action_type' => 'test_action',
        ]);

        // 3. Try to update it (should throw exception)
        $exceptionThrownOnUpdate = false;
        try {
            $log->update(['action_type' => 'malicious_update']);
        } catch (Exception $e) {
            $exceptionThrownOnUpdate = true;
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertTrue($exceptionThrownOnUpdate, "Updating audit log should throw an exception");

        // Verify it was not updated in the DB
        $log->refresh();
        $this->assertEquals('test_action', $log->action_type);

        // 4. Try to delete it (should throw exception)
        $exceptionThrownOnDelete = false;
        try {
            $log->delete();
        } catch (Exception $e) {
            $exceptionThrownOnDelete = true;
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertTrue($exceptionThrownOnDelete, "Deleting audit log should throw an exception");

        // Verify it still exists in the DB
        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
        ]);
    }
}
