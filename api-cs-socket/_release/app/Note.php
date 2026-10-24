<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class Note extends Model
{

    protected $fillable = [
        'room_id',
        'member_id',
        'title',
        'status',
        'created_user',
        'updated_user'
    ];

    public $rules_search = [
        'member_id' => 'digits_between:0,10',
        'username' => 'r_alpha_num',
        'status' => 'boolean',
        'start_time' => 'date_format:Y-m-d H:i:s',
        'end_time' => 'date_format:Y-m-d H:i:s',
        'status' => 'boolean',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_create = [
        'member_id' => 'digits_between:0,10',
        'title' => 'required|between:0,1024',
        'status' => 'boolean'
    ];

    public $rules_update = [
        'note_id' => 'required|digits_between:0,10',
        'title' => 'required|between:0,1024',
        'status' => 'required|boolean'
    ];
}
