<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateBetRecordUniqueTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ag_bet_records', function (Blueprint $table) {
            $table->unique('bill_no');
        });
        Schema::table('joker_bet_records', function (Blueprint $table) {
            $table->unique('code');
        });
        Schema::table('great_wall_bet_records', function (Blueprint $table) {
            $table->unique('gameSerialId');
        });
        Schema::table('ace333_bet_records', function (Blueprint $table) {
            $table->unique('referenceID');
        });
        Schema::table('vplay_bet_records', function (Blueprint $table) {
            $table->unique('WinloseID');
        });
    }
}