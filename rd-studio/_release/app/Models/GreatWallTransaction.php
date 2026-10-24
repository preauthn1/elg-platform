<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GreatWallTransaction extends Model
{

    protected $fillable = [
        'externalTransactionId',
        'operationMethod',
        'playerAccount',
        'transAmount',
        'result',
        'createTime',
        'utc_created_time',
    ];
}