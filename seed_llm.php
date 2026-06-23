<?php
$c = App\Models\Client::first();
App\Models\LlmUsage::create(['client_id' => $c->id, 'provider' => 'openai', 'model' => 'gpt-4o', 'task_type' => 'log_check', 'prompt_tokens' => 1500, 'completion_tokens' => 300, 'estimated_cost_usd' => 0.012]);
App\Models\LlmUsage::create(['client_id' => $c->id, 'provider' => 'anthropic', 'model' => 'claude-3-5-sonnet', 'task_type' => 'validate', 'prompt_tokens' => 5000, 'completion_tokens' => 1000, 'estimated_cost_usd' => 0.030]);
echo "Done seeding.\n";
