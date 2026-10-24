<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentAuthentication extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at'
    ];

    protected $hidden = [
        'secret',
    ];

    public function setGoogle2faSecretAttribute($value)
    {
        $this->attributes['secret'] = encrypt($value);
    }

    /**
     * Decrypt the user's google_2fa secret.
     *
     * @param  string  $value
     * @return string
     */
    public function getGoogle2faSecretAttribute($value)
    {
        return decrypt($value);
    }
}
