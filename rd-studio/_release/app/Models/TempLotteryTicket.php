<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TempLotteryTicket extends Model
{
    //修改时不更新updated_at
    const UPDATED_AT = null;

    protected $guarded = ['id', 'ticket_number'];
}
