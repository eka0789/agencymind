<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ValidationProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'name',
        'rules_json',
    ];

    protected $casts = [
        'rules_json' => 'array',
    ];

    /**
     * The client this validation profile belongs to.
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
