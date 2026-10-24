<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertUserMenusSr02 extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('user_menus')->insert(array(
            'code' => 'sr02',
            'name' => '開洗分統計表',
            'parent' => '0',
            'url' => '/report/sr02',
            'symbol' => 'list_alt',
            'action' => '',
            'admin' => 0
        ));
    }
}