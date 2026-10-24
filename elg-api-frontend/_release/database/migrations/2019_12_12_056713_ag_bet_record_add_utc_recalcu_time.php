<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AgBetRecordAddUtcRecalcuTime extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        //AG
        Schema::table('ag_bet_records', function (Blueprint $table) {
            $table->dateTime('utc_recalcu_time')
                ->after('recalcu_time')
                ->comment('結算時間(UTC時區)');
        });
    }
}