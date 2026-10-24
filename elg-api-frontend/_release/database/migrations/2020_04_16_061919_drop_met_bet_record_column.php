<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class DropMetBetRecordColumn extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('mt_bet_records', function (Blueprint $table)
    {
        $table->dropColumn('CNY_betAmount');
        $table->dropColumn('CNY_winAmount');
    });
    }
}
