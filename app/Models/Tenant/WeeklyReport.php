<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class WeeklyReport extends Model
{
    protected $fillable = [
        'period_start',
        'period_end',
        'draft_content',
        'status',
        'reviewed_by_user_id',
        'sent_at'
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'sent_at' => 'datetime'
    ];
}
