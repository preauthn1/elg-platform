<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class HaolongAddUtcColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('haolong_bet_records', function (Blueprint $table) {
            $table->dateTime('utc_bet_time')
                ->after('bet_time')
                ->nullable()
                ->comment('投注的UTC時間');
        });
    }
}
