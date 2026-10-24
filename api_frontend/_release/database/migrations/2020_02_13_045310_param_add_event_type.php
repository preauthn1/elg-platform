<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class paramAddEventType extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('prize_types')->insert(array(
            'id' => '4',
            'name' => 'Lottery ticket',
            'status' => 1,
            'admin' => 0
        ));

        DB::table('prize_type_names')->insert(array(
            'language_id' => 2,
            'type_id' => 4,
            'name' => '摸彩券',
            'admin' => 0
        ));
    }
}