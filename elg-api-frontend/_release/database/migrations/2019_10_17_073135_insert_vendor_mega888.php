<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertVendorMega888 extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('vendors')->insert(array(
            'id' => 10,
            'name' => 'Mega888',
            'image' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157010057310.png',
            'rate' => 1,
            'percentage' => 0,
            'need_login' => 1,
            'status' => 2,
            'sort' => 95,
            'admin' => 1
        ));

        DB::table('vendors')->insert(array(
            'id' => 11,
            'name' => 'Xe88',
            'image' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157010045355.png',
            'rate' => 1,
            'percentage' => 0,
            'need_login' => 1,
            'status' => 2,
            'sort' => 96,
            'admin' => 1
        ));
    }
}