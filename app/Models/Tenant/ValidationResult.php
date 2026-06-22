<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ValidationResult extends Model
{
    // The database table has created_at but no updated_at
    public $timestamps = false;

    protected $table = 'validation_results';

    protected $fillable = [
        'validation_profile_id',
        'triggered_by_user_id',
        'overall_status',
        'results_json',
    ];

    protected $casts = [
        'results_json' => 'array',
        'created_at' => 'datetime',
    ];
}
