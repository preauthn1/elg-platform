<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class UserMenu extends Model
{

    public $rules_search = [
        'user_id' => 'required|digits_between:0,10'
    ];

    protected $fillable = [
        'code',
        'name',
        'parent',
        'url',
        'symbol',
        'action',
        'admin'
    ];
}
