<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'contact_email',
        'schema_name',
        'status',
        'sop_validation_profile_id',
    ];

    /**
     * Users who have access to this client.
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_client_access')
            ->withPivot('access_level')
            ->withTimestamps();
    }

    /**
     * Validation profiles owned by this client.
     */
    public function validationProfiles()
    {
        return $this->hasMany(ValidationProfile::class);
    }

    /**
     * The SOP validation profile currently active for this client.
     */
    public function sopValidationProfile()
    {
        return $this->belongsTo(ValidationProfile::class, 'sop_validation_profile_id');
    }
}
