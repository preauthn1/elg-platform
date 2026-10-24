<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterTransactionTypeTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->integer('type')->comment('開洗分狀態0:失敗,1:成功,2:用戶拒絕,3:已過期,4:等待用戶同意')->change();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->integer('type')->comment('類型1:文字，2:圖片，3:紅包，4:聲音，5:影片，6:洗分')->change();
            $table->integer('red_envelope_id')->comment('紅包編號')->change();
            $table->integer('transaction_id')->after('red_envelope_id')
                ->nullable()
                ->comment('洗分編號');
        });
    }
}