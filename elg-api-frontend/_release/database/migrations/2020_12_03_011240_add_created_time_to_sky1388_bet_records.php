<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddCreatedTimeToSky1388BetRecords extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('sky1388_bet_records', function (Blueprint $table) {
            //
            $table->datetime('created_time')->after('bet_time')->comment('注單時間(utc)')->index();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('sky1388_bet_records', 'created_time'))
        {
            Schema::table('sky1388_bet_records', function (Blueprint $table)
            {
                $table->dropColumn('created_time');
            });
        }
    }
}
