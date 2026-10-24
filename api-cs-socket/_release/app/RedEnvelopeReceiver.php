<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class RedEnvelopeReceiver extends Model
{

    protected $fillable = [
        'red_envelope_id',
        'member_id',
        'amount',
        'remark',
        'status',
        'third_party_result'
    ];
}
