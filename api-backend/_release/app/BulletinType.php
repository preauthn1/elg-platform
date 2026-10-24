<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class BulletinType extends Model
{

    public $rules_show = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        'name' => 'required|between:0,30',
        'names' => 'array'
    ];

    public $rules_update = [
        'id' => 'required|digits_between:0,10',
        'name' => 'between:0,30',
        'names' => 'array'
    ];

    public $rules_name = [
        'language_id' => 'required|digits_between:0,10',
        'name' => 'required|between:0,30'
    ];

    public $rules_delete = [
        'id' => 'required|digits_between:0,10'
    ];

    protected $fillable = [
        'name',
        'status',
        'admin'
    ];
}
