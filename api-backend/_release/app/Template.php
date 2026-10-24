<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class Template extends Model
{

    public $rules_search = [
        'status' => 'boolean',
        'page' => 'required|integer',
        'per_page' => 'required|integer'
    ];

    public $rules_show = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        'name' => 'required|between:0,60',
        'thumbnail' => 'required|base64image',
        'status' => 'boolean'
    ];

    public $rules_update = [
        'name' => 'between:0,60',
        'thumbnail' => 'base64image',
        'status' => 'boolean'
    ];

    public $rules_delete = [
        'id' => 'required|digits_between:0,10'
    ];

    protected $fillable = [
        'name',
        'thumbnail',
        'status',
        'created_at',
        'updated_at',
        'admin'
    ];
}
