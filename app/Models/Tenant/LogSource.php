<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use Laravel\Sanctum\HasApiTokens;

class LogSource extends Model
{
    use HasApiTokens, HasFactory;

    protected $table = 'log_sources';

    protected $fillable = [
        'type',
        'config_encrypted',
        'polling_interval_seconds',
        'last_polled_at',
        'status',
    ];

    protected $casts = [
        'last_polled_at' => 'datetime',
    ];

    /**
     * Log entries from this source.
     */
    public function logEntries()
    {
        return $this->hasMany(LogEntry::class, 'log_source_id');
    }
}
