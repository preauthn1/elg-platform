<?php
namespace App\Models;

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
        'ordery_type_id' => 'digits_between:0,10',
        'start_time' => 'date_format:Y-m-d H:i:s',
        'end_time' => 'date_format:Y-m-d H:i:s',
        'status' => 'in:0,1,2',
        'page' => 'required|numeric',
        'per_page' => 'required|numeric'
    ];

    public $rules_show = [
        'id' => 'required|digits_between:0,10'
    ];

    public $rules_create = [
        'order_type_id' => 'required|digits_between:0,10',
        'amount' => 'required|numeric',
        'callback' => 'required|url'
    ];
}
