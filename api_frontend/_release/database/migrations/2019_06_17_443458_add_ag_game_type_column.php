<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddAgGameTypeColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ag_bet_records', function (Blueprint $table) {
            $table->string('game_type')
                ->after('bet_time')
                ->nullable()
                ->comment('遊戲代碼');
        });
    }
}
