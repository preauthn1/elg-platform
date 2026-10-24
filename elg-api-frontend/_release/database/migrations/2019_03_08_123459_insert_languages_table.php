<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertLanguagesTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('languages')->insert(array(
            'code' => 'en',
            'name' => '英文',
            'status' => 1,
            'admin' => 1
        ));
        DB::table('languages')->insert(array(
            'code' => 'zh-TW',
            'name' => '繁中',
            'status' => 1,
            'admin' => 1
        ));
        DB::table('languages')->insert(array(
            'code' => 'zh-CN',
            'name' => '簡中',
            'status' => 1,
            'admin' => 1
        ));
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {}
}
