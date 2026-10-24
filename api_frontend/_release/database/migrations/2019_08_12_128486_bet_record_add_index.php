<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class BetRecordAddIndex extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ag_bet_records', function (Blueprint $table) {
            $table->index('bill_no');
        });

        Schema::table('joker_bet_records', function (Blueprint $table) {
            $table->index('code');
        });

        Schema::table('haolong_bet_records', function (Blueprint $table) {
            $table->index('bet_id');
        });

        Schema::table('great_wall_bet_records', function (Blueprint $table) {
            $table->index('gameSerialId');
        });
    }
}
