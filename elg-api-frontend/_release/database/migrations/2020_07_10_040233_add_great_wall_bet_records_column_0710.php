<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddGreatWallBetRecordsColumn0710 extends Migration
{
    protected $table_name = 'great_wall_bet_records';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn($this->table_name, 'gameId'))
        {
            DB::update("ALTER TABLE " . $this->table_name . " Add COLUMN gameId varchar(4) AS (LEFT(themeId,4)) COMMENT '遊戲ID, 參照themeId' AFTER themeId;");

            Schema::table($this->table_name, function (Blueprint $table) {
                $table->index('gameId');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn($this->table_name, 'gameId'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn('gameId');
            });
        }
    }
}
