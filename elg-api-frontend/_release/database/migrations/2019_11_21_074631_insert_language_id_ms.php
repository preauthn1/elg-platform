<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertLanguageIdMs extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        //新增馬來文
        DB::table('languages')->insert(array(
            'id' => 4,
            'name' => 'Melay',
            'code' => 'ms-my',
            'ag' => 3,
            'lucky' => 'en',
            'status' => 1,
            'admin' => 0
        ));

        //新增印尼文
        DB::table('languages')->insert(array(
            'id' => 5,
            'name' => 'Indonesia',
            'code' => 'id-id',
            'ag' => 3,
            'lucky' => 'en',
            'status' => 1,
            'admin' => 0
        ));

        //打開簡中
        DB::table('languages')
            ->where('id', 3)
            ->update(['status' => 1]);
    }
}