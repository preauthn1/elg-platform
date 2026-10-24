<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddPtBetRecordsColumn0708 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('pt_bet_records', 'game_name_modified'))
        {
            DB::update("ALTER TABLE pt_bet_records Add COLUMN game_name_modified varchar(30) AS (REPLACE(SUBSTRING_INDEX(game_name, '(', -1), ')', ''));");
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('pt_bet_records', 'game_name_modified'))
        {
            Schema::table('pt_bet_records', function (Blueprint $table) {
                $table->dropColumn('game_name_modified');
            });
        }
    }
}
