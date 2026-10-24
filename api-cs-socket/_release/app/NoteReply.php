<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class NoteReply extends Model
{

    protected $fillable = [
        'note_id',
        'user_id',
        'content',
        'updated_user',
        'status'
    ];

    public $rules_search = [
        'note_id' => 'required|digits_between:0,10',
        'start_time' => 'date_format:Y-m-d H:i:s',
        'end_time' => 'date_format:Y-m-d H:i:s',
        'status' => 'boolean',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_create = [
        'note_id' => 'digits_between:0,10',
        'content' => 'required|between:0,1024',
        'status' => 'boolean'
    ];

    public $rules_update = [
        'content_id' => 'digits_between:0,10',
        'content' => 'required|between:0,1024',
        'status' => 'boolean'
    ];

    public $rules_delete = [
        'content_id' => 'required|digits_between:0,10'
    ];
}
