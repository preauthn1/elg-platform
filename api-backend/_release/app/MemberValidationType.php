<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class MemberValidationType extends Model
{

    protected $fillable = [
        'name',
        'status',
        'admin'
    ];
}
