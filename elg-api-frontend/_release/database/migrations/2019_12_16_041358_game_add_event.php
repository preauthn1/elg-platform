<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class GameAddEvent extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('games', function (Blueprint $table) {
            $table->boolean('join_bet_event')
                ->after('type_id')
                ->default(0)
                ->comment('是否可參加投注額活動，0:不可，1:可以');
            $table->boolean('join_win_event')
                ->after('join_bet_event')
                ->default(0)
                ->comment('是否可參加贏分活動，0:不可，1:可以');
        });
    }
}