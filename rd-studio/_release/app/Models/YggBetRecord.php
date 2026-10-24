<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class YggBetRecord extends Model
{

    protected $fillable = [
        'record_id',
        'reference',
        'topOrg',
        'org',
        'loginname',
        'currency',
        'type',
        'amount',
        'beforeAmount',
        'afterAmount',
        'gameName',
        'createTime',
        'admin'
    ];
}