<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class GwRecordAddUtcColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('great_wall_bet_records', function (Blueprint $table) {
            $table->dateTime('utc_created_time')
                ->after('historyCreatedTime')
                ->nullable()
                ->comment('遊戲記錄建立UTC時間');
        });
    }
}
