<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class AdvertisementPath extends Model
{

    public $rules_search = [
        'status' => 'boolean',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_show = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        'name' => 'required|r_alpha_dash|between:0,60',
        'column' => 'required|digits_between:1,5',
        'width' => 'required|digits_between:1,5',
        'height' => 'required|digits_between:1,5',
        'status' => 'required|boolean'
    ];

    public $rules_update = [
        'name' => 'r_alpha_dash',
        'column' => 'digits_between:1,5',
        'width' => 'digits_between:1,5',
        'height' => 'digits_between:1,5',
        'status' => 'boolean'
    ];

    protected $fillable = [
        'name',
        'column',
        'width',
        'height',
        'status',
        'admin'
    ];
}
