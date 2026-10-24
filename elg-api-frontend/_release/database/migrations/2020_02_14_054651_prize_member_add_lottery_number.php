<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class PrizeMemberAddLotteryNumber extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('prize_members', function (Blueprint $table) {
            $table->string('lottery_number')
                ->after('content_id')
                ->nullable()
                ->comment('摸彩卷號碼');
        });

        Schema::table('prize_members', function ($table) {
            $table->integer('status')->comment('狀態(0:未使用,1:已使用)、(0:未開始,1:中獎,2:未中獎)')->change();
        });
    }
}