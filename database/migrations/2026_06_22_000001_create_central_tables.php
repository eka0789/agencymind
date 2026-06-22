<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create validation_profiles first (without clients FK to avoid dependency loop)
        Schema::create('validation_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('rules_json');
            $table->timestamps();
        });

        // 2. Create clients table
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('contact_email');
            $table->string('schema_name')->unique();
            $table->enum('status', ['active', 'paused', 'archived'])->default('active');
            $table->foreignId('sop_validation_profile_id')->nullable()->constrained('validation_profiles')->nullOnDelete();
            $table->timestamps();
        });

        // 3. Add client_id FK to validation_profiles table
        Schema::table('validation_profiles', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('id')->constrained('clients')->cascadeOnDelete();
        });

        // 4. Create user_client_access pivot table
        Schema::create('user_client_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->enum('access_level', ['read', 'write', 'admin'])->default('read');
            $table->timestamps();
        });

        // 5. Create audit_logs table (append-only, no updated_at)
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('action_type');
            $table->text('command_text')->nullable();
            $table->json('payload_json')->nullable();
            $table->text('result_summary')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('user_client_access');
        
        // Remove FK before dropping validation_profiles
        if (Schema::hasColumn('validation_profiles', 'client_id')) {
            Schema::table('validation_profiles', function (Blueprint $table) {
                $table->dropForeign(['client_id']);
            });
        }
        
        Schema::dropIfExists('clients');
        Schema::dropIfExists('validation_profiles');
    }
};
