<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateXe88LogIndex extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('xe88_accounts', function (Blueprint $table) {
            $table->index('member_id');
        });

        Schema::table('xe88_daily_wins', function (Blueprint $table) {
            $table->unique(['date', 'username']);
            $table->index('date');
            $table->index('username');
        });

        Schema::table('xe88_bet_records', function (Blueprint $table) {
            $table->index('username');
            $table->index('begin_money');
            $table->index('game_log');
            $table->index('utc_log_time');
        });
    }
}