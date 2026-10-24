<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'role_id',
        'parent_id',
        'username',
        'password',
        'API_USER',
        'API_KEY',
        'nickname',
        'thumbnail',
        'limit_of_credit',
        'used_credit',
        'last_login',
        'status',
        'admin'
    ];

    public $rules_search = [
        'status' => 'boolean',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_view = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        'parent_id' => 'digits_between:0,10',
        'username' => 'required|r_alpha_num|between:4,10|unique:users',
        'nickname' => 'between:0,50',
        'password' => 'required|between:4,15|confirmed',
        'thumbnail' => 'base64image',
        'limit_of_credit' => 'digits_between:0,10',
        'status' => 'boolean'
    ];

    public $rules_update = [
        'id' => 'required|digits_between:0,10',
        'parent_id' => 'digits_between:0,10',
        'password' => 'between:4,15|confirmed',
        'nickname' => 'between:0,50',
        'thumbnail' => 'base64image',
        'limit_of_credit' => 'digits_between:0,10',
        'status' => 'boolean'
    ];

    public $rules_password_self = [
        'password' => 'required|between:4,15',
        'new_password' => 'required|between:4,15|confirmed'
    ];

    public $rules_password = [
        'id' => 'required|digits_between:0,10',
        'new_password' => 'required|between:4,15|confirmed'
    ];

    public $rules_delete = [
        'id' => 'required|digits_between:0,10'
    ];
}
