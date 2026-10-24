<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddSummaryBetRecordColumn0619 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('summary_bet_records', function (Blueprint $table) {
            $table->dateTime('data_time')
                ->after('summary_time')
                ->comment('數據時間');
            $table->tinyInteger('correction')
                ->after('share_range')
                ->default(0)
                ->comment('改正標示');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('summary_bet_records', function (Blueprint $table) {
            $table->dropColumn('data_time');
            $table->dropColumn('correction');
        });
    }
}
