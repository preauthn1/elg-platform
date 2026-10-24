<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertVendorAce333Table extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('vendors')->insert(array(
            'id' => 5,
            'name' => 'GreatWall99',
            'rate' => 1,
            'percentage' => 0,
            'need_login' => 1,
            'status' => 1,
            'admin' => 1
        ));

        DB::table('vendors')->insert(array(
            'id' => 6,
            'name' => 'Ace333',
            'rate' => 1,
            'percentage' => 0,
            'need_login' => 1,
            'status' => 2,
            'admin' => 1
        ));

        DB::table('vendors')->insert(array(
            'id' => 7,
            'name' => '鬥雞',
            'rate' => 1,
            'percentage' => 0,
            'need_login' => 1,
            'status' => 2,
            'admin' => 1
        ));
        DB::table('vendors')->insert(array(
            'id' => 8,
            'name' => 'Vplay',
            'rate' => 1,
            'percentage' => 0,
            'need_login' => 1,
            'status' => 2,
            'admin' => 1
        ));
    }
}