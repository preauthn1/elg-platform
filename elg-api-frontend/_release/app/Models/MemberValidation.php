<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberValidation extends Model
{

    public $rules_search = [
        'status' => 'in:0,1,2'
    ];

    public $rules_show = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        'type_id' => 'required|numeric',
        'image' => 'required|base64image'
    ];

    public $rules_update = [
        'id' => 'required|digits_between:0,10',
        'image' => 'required|base64image'
    ];

    protected $fillable = [
        'type_id',
        'member_id',
        'image',
        'remark',
        'status',
        'admin'
    ];
}
