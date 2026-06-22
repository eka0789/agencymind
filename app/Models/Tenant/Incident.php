<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Incident extends Model
{
    protected $table = 'incidents';

    protected $fillable = [
        'severity',
        'title',
        'rca_summary',
        'rca_confidence',
        'related_log_entry_ids',
        'status',
        'resolved_by_user_id',
        'resolved_at',
    ];

    protected $casts = [
        'related_log_entry_ids' => 'array',
        'resolved_at' => 'datetime',
    ];
}
