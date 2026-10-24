<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddEventDetailColumn0810 extends Migration
{
    protected $table_name = 'event_details';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn($this->table_name, 'game_type_id'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->tinyInteger('game_type_id')
                    ->after('rate')
                    ->nullable()
                    ->comment('遊戲分類');
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
        if (Schema::hasColumn($this->table_name, 'game_type_id'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn('game_type_id');
            });
        }
    }
}
