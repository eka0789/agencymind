<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Exception;

class AuditLog extends Model
{
    // No updated_at, created_at is managed by database default
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'client_id',
        'action_type',
        'command_text',
        'payload_json',
        'result_summary',
    ];

    protected $casts = [
        'payload_json' => 'array',
    ];

    public static function boot()
    {
        parent::boot();

        // Prevent updating
        static::updating(function ($model) {
            throw new Exception("Audit logs are append-only. Modification is prohibited.");
        });

        // Prevent deleting
        static::deleting(function ($model) {
            throw new Exception("Audit logs are append-only. Deletion is prohibited.");
        });
    }

    /**
     * The user who performed the action.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The client affected by the action.
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
