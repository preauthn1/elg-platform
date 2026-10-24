<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class LuckyAddUtcColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('lucky_bet_records', function (Blueprint $table) {
            $table->dateTime('utc_bet_time')
                ->after('bet_time')
                ->comment('UTC投注時間');
        });
    }
}