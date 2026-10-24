<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateGreatWallTransaction extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {

        Schema::create('great_wall_transactions', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('externalTransactionId')->nullable()->comment('GW99系統編號');
            $table->tinyInteger('operationMethod')->default(0)->comment('執行動作，1: open score, 2: shuffle score, 3: Red envelope (Lucky bag, New Year gift)');
            $table->string('playerAccount')->nullable()->comment('玩家帳號');
            $table->decimal('transAmount', 13, 7)->comment('金額');
            $table->string('remark')->nullable()->comment('備註');
            $table->tinyInteger('result')->default(0)->comment('狀態，0 : unresolved, 1 : success, 2 : failure');
            $table->string('createTime')->nullable()->comment('建立時間(+8時區)');
            $table->dateTime('utc_created_time')->nullable()->comment('建立時間(+0時區)');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });
    }
}