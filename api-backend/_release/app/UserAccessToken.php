<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class UserAccessToken extends Model
{

    protected $fillable = [
        'user_id',
        'token',
        'expire_date',
        'admin'
    ];
}
 