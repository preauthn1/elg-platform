<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class EventDetail extends Model
{

    protected $fillable = [
        'event_id',
        'action_id',
        'order_type_id',
        'vendor_id',
        'game_id',
        'rate',
        'point',
        'start_time',
        'end_time',
        'status',
        'admin'
    ];
}
