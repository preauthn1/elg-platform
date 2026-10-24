<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class VplayAddPlayerIdColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('vplay_accounts', function (Blueprint $table) {
            $table->string('player_id')
                ->after('username')
                ->nullable()
                ->comment('玩家帳號');
        });
    }
}