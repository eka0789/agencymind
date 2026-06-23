<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LlmUsage extends Model
{
    protected $fillable = [
        'client_id',
        'provider',
        'model',
        'task_type',
        'prompt_tokens',
        'completion_tokens',
        'estimated_cost_usd',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
