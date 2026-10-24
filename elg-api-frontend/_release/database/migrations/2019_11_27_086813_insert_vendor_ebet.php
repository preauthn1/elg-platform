<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertVendorEbet extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('vendors')->insert(array(
            'id' => 13,
            'name' => 'eBET',
            'image' => '',
            'rate' => 1,
            'percentage' => 0,
            'need_login' => 0,
            'status' => 0,
            'sort' => 99,
            'admin' => 1
        ));
    }
}