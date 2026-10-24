<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertVendorCokeFightingTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('vendors')->insert(array(
            'id' => 9,
            'name' => 'Coke Fighting',
            'rate' => 1,
            'percentage' => 0,
            'need_login' => 0,
            'status' => 2,
            'admin' => 1
        ));

        DB::table('vendors')->where('id', 7)->update(array(
            'name' => '沒有雞的鬥雞',
            'status' => 0
        ));
    }
}