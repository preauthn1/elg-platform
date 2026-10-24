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
            'status' => 1,
            'admin' => 0
        ));

        //新增印尼文
        DB::table('languages')->insert(array(
            'id' => 5,
            'name' => 'Indonesia',
            'code' => 'id-id',
            'status' => 1,
            'admin' => 0
        ));

        DB::table('languages')
            ->where('id', 1)
            ->update(['name' => 'English']);

        DB::table('languages')
            ->where('id', 2)
            ->update(['name' => '繁體中文', 'code' => 'zh-tw']);
        DB::table('languages')
            ->where('id', 3)
            ->update(['name' => '简体中文', 'code' => 'zh-cn']);
    }
}