<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertVendorFake extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('vendors')->insert(array(
            'id' => 16,
            'name' => 'Octavian',
            'image' => '',
            'rate' => 1,
            'percentage' => 0,
            'need_login' => 0,
            'status' => 0,
            'sort' => 99,
            'admin' => 1
        ));

        DB::table('vendors')->insert(array(
            'id' => 17,
            'name' => 'Comming soon1',
            'image' => '',
            'rate' => 1,
            'percentage' => 0,
            'need_login' => 0,
            'status' => 0,
            'sort' => 99,
            'admin' => 1
        ));

        DB::table('vendors')->insert(array(
            'id' => 18,
            'name' => 'Comming soon2',
            'image' => '',
            'rate' => 1,
            'percentage' => 0,
            'need_login' => 0,
            'status' => 0,
            'sort' => 99,
            'admin' => 1
        ));

        DB::table('vendors')->insert(array(
            'id' => 19,
            'name' => 'Comming soon3',
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