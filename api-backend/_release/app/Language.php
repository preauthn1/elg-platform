<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class Language extends Model
{

    public $rules_update = [
        'id' => 'required|digits_between:0,10',
        'status' => 'required|boolean'
    ];

    protected $fillable = [
        'name',
        'code',
        'status'
    ];
}
