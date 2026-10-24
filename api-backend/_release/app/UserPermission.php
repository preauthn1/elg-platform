<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class UserPermission extends Model
{

    public $rules_update = [
        'user_id' => 'required|integer',
        'menus' => 'required|array'
    ];

    protected $fillable = [
        'user_id',
        'menu_id',
        'action',
        'admin'
    ];
}
