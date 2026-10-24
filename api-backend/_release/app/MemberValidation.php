<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class MemberValidation extends Model
{

    public $rules = [
        'photo' => 'required|mimes:jpeg,bmp,png,jpg',
        'type' => 'required|numeric'
    ];

    protected $fillable = [
        'photo',
        'type'
    ];
}
 