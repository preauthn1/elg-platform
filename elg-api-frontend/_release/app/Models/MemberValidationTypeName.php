<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberValidationTypeName extends Model
{

    protected $fillable = [
        'language_id',
        'type_id',
        'name',
        'admin'
    ];
}
