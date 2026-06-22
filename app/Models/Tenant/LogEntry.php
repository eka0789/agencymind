<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class LogEntry extends Model
{
    // No updated_at field in log_entries table
    public $timestamps = false;

    protected $table = 'log_entries';

    protected $fillable = [
        'log_source_id',
        'timestamp',
        'level',
        'source_type',
        'raw_message',
        'stack_trace',
        'http_status',
        'context_json',
        'is_parsed',
        'created_at',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
        'context_json' => 'array',
        'is_parsed' => 'boolean',
        'created_at' => 'datetime',
    ];

    /**
     * The log source this entry belongs to.
     */
    public function logSource()
    {
        return $this->belongsTo(LogSource::class, 'log_source_id');
    }
}
