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
        // 1. Create log_sources table
        Schema::create('log_sources', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['push_api', 'ssh_pull', 'webhook']);
            $table->text('config_encrypted');
            $table->integer('polling_interval_seconds')->default(300);
            $table->timestamp('last_polled_at')->nullable();
            $table->string('status')->default('active'); // active, inactive, error
            $table->timestamps();
        });

        // 2. Create log_entries table
        Schema::create('log_entries', function (Blueprint $table) {
            $table->id();
            // We use simple unsignedBigInteger instead of constrained FK because log_sources is also in tenant schema, but we don't have constraints across schemas if not resolved.
            // Actually log_sources is in the same tenant schema, so we can define constrained FK.
            $table->foreignId('log_source_id')->constrained('log_sources')->cascadeOnDelete();
            $table->timestamp('timestamp')->index();
            $table->string('level')->index();
            $table->string('source_type'); // laravel, nginx, apache, etc.
            $table->text('raw_message');
            $table->text('stack_trace')->nullable();
            $table->integer('http_status')->nullable()->index();
            $table->jsonb('context_json')->nullable();
            $table->boolean('is_parsed')->default(false);
            $table->timestamp('created_at')->useCurrent();
            
            // Composite index for fast threshold query
            $table->index(['timestamp', 'level', 'http_status']);
        });

        // 3. Create incidents table
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->enum('severity', ['warning', 'critical', 'performance']);
            $table->string('title');
            $table->text('rca_summary')->nullable();
            $table->enum('rca_confidence', ['high', 'medium', 'low'])->nullable();
            $table->jsonb('related_log_entry_ids'); // Array of log_entry IDs
            $table->enum('status', ['open', 'acknowledged', 'resolved'])->default('open');
            // resolved_by_user_id points to global users table (in public schema)
            // PostgreSQL allows FK to another schema if qualified, but in Laravel tenant connections, it is cleaner to keep it as bigInteger without strict FK constraints or define it as bigInteger.
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->timestamps();
            $table->timestamp('resolved_at')->nullable();
        });

        // 4. Create validation_results table
        Schema::create('validation_results', function (Blueprint $table) {
            $table->id();
            // validation_profile_id is in global schema (public)
            $table->unsignedBigInteger('validation_profile_id');
            // triggered_by_user_id is in global schema (public)
            $table->unsignedBigInteger('triggered_by_user_id');
            $table->enum('overall_status', ['pass', 'pass_with_notes', 'fail']);
            $table->json('results_json');
            $table->timestamp('created_at')->useCurrent();
        });

        // 5. Create weekly_reports table
        Schema::create('weekly_reports', function (Blueprint $table) {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            $table->text('draft_content');
            $table->enum('status', ['draft', 'reviewed', 'sent'])->default('draft');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weekly_reports');
        Schema::dropIfExists('validation_results');
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('log_entries');
        Schema::dropIfExists('log_sources');
    }
};
