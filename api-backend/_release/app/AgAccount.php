<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class AgAccount extends Model
{

    protected $fillable = [
        'username',
        'admin'
    ];
}
