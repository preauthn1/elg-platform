<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class MemberValidation extends Model
{

    public $rules_search = [
        'member_id' => 'required|digits_between:0,10',
        'status' => 'digits_between:0,2'
    ];

    public $rules_show = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_update = [
        'id' => 'required|digits_between:0,10',
        'status' => 'in:0,1,2',
        'remark' => 'between:0,60'
    ];

    protected $fillable = [
        'member_id',
        'type_id',
        'image',
        'remark',
        'status',
        'admin'
    ];
}
