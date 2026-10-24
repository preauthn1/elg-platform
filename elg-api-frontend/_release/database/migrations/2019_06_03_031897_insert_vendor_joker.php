<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertVendorJoker extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('vendors')->insert(array(
            'name' => 'Joker',
            'rate' => 0.6,
            'percentage' => 0,
            'status' => 1,
            'admin' => 1
        ));
        DB::table('vendors')->insert(array(
            'name' => 'SCR(918KISS)',
            'rate' => 0.6,
            'percentage' => 0,
            'status' => 1,
            'admin' => 1
        ));
        DB::table('vendors')->insert(array(
            'name' => '豪龍',
            'rate' => 0.6,
            'percentage' => 0,
            'status' => 1,
            'admin' => 1
        ));
    }
}
