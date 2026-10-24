<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DgAccount extends Model
{
    protected $name = 'DG';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at'
    ];

    public function getName() {
        return $this->name;
    }
}
