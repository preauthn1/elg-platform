<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ChangePgRoundBetRecordColumn extends Migration
{
 /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pg_round_bet_records', function ($table) {
            $table->string('RoundData')->nullable()->change();
            $table->string('ExternalFreegameId')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pg_round_bet_records', function ($table) {
            $table->string('RoundData')->nullable(false)->change();
            $table->string('ExternalFreegameId')->nullable(false)->change();
        });
    }
}
