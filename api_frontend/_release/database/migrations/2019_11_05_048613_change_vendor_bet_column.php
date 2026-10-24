<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ChangeVendorBetColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        //ag
        Schema::table('ag_bet_records', function ($table) {
            $table->decimal('net_amount', 13, 7)->default(0)->change();
            $table->decimal('bet_amount', 13, 7)->default(0)->change();
            $table->decimal('valid_bet_amount', 13, 7)->default(0)->change();
        });

        //joker
        Schema::table('joker_bet_records', function ($table) {
            $table->decimal('amount', 13, 7)->default(0)->change();
            $table->decimal('result', 13, 7)->default(0)->change();
        });

        //918
        Schema::table('scr_daily_wins', function ($table) {
            $table->decimal('win', 13, 7)->default(0)->change();
        });

        //豪龍
        Schema::table('haolong_bet_records', function ($table) {
            $table->decimal('bet_amount', 13, 7)->default(0)->change();
            $table->decimal('strike', 13, 7)->default(0)->change();
        });

        //GW99
        Schema::table('great_wall_bet_records', function ($table) {
            $table->decimal('valueBefore', 13, 7)->default(0)->change();
            $table->decimal('valueAfter', 13, 7)->default(0)->change();
            $table->decimal('betCoin', 13, 7)->default(0)->change();
            $table->decimal('winAmount', 13, 7)->default(0)->change();
            $table->decimal('availTotalBet', 13, 7)->default(0)->change();
            $table->decimal('availTotalWin', 13, 7)->default(0)->change();
        });

        //ACE333
        Schema::table('ace333_bet_records', function ($table) {
            $table->decimal('betAmount', 13, 7)->default(0)->change();
            $table->decimal('winAmount', 13, 7)->default(0)->change();
            $table->decimal('jackpotContributionAmt', 13, 7)->default(0)->change();
        });

        //Vplay
        Schema::table('vplay_bet_records', function ($table) {
            $table->decimal('Bet', 13, 7)->default(0)->change();
            $table->decimal('WinLose', 13, 7)->default(0)->change();
            $table->decimal('GongPoint', 13, 7)->default(0)->change();
            $table->decimal('BetLM', 13, 7)->default(0)->change();
            $table->decimal('WinLoseLM', 13, 7)->default(0)->change();
            $table->decimal('GetJPMoney', 13, 7)->default(0)->change();
        });

        //Mega888
        Schema::table('mega888_daily_wins', function ($table) {
            $table->decimal('bet', 13, 7)->default(0)->change();
            $table->decimal('win', 13, 7)->default(0)->change();
        });

    }
}