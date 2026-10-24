<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class CountryName extends Model
{

    protected $fillable = [
        'language_id',
        'country_id',
        'name',
        'admin'
    ];
}
