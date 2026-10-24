<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
