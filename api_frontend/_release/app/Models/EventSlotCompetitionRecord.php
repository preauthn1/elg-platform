<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventSlotCompetitionRecord extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at'
    ];

    public $rules_search = [
        'event_id' => 'required|numeric',
        'no' => 'required|numeric'
    ];
    public $rules_receive = [
        'event_id' => 'required|numeric',
        'no' => 'required|numeric'
    ];
}
