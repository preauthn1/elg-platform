<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{

    protected $fillable = [
        'order_no',
        'order_type_id',
        'currency_id',
        'member_id',
        'amount',
        'point',
        'balance',
        'callback',
        'status',
        'created_at',
        'updated_at',
        'admin'
    ];

    public $rules_search = [
        'order_type_id' => 'numeric',
        'currency_id' => 'numeric',
        'member_id' => 'numeric',
        'amount' => 'numeric',
        'status' => 'in:0,1,2',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_show = [
        'id' => 'required|numeric'
    ];
}
