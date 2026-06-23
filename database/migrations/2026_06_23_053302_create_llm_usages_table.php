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
        Schema::create('llm_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('provider'); // openai, anthropic
            $table->string('model'); // gpt-4o, claude-3-5-sonnet
            $table->string('task_type')->nullable(); // rca, report, validate
            $table->integer('prompt_tokens')->default(0);
            $table->integer('completion_tokens')->default(0);
            $table->decimal('estimated_cost_usd', 10, 6)->default(0);
            $table->timestamps();
            
            // For fast querying in Pulse
            $table->index('created_at');
            $table->index('client_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('llm_usages');
    }
};
