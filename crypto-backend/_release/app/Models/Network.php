<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Network extends Model
{
    //

    public function currency(){
        return $this->belongsTo(Currency::class);
    }

    public function type(){
        return $this->belongsTo(NetworkType::class);
    }
}
