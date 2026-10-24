<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateTransactionLogType extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('transaction_logs', function (Blueprint $table) {
            $table->integer('type')
                ->comment('類型1:平台轉入遊戲商,2:遊戲商轉入平台,3:訂單儲值,4:開分,5:洗分,6:發紅包,7:領紅包,8:紅包取消(用戶手動),9:紅包退還(系統自動),10:開分(贈點),11:拒絕洗分,12:退還洗分,13:活動獎勵')->change();
        });
    }
}