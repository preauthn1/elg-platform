<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class UserLoginLog extends Model
{

    protected $fillable = [
        'user_id',
        'ip',
        'status',
        'admin'
    ];
}
