<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateYggBetRecordTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ygg_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('record_id')->comment('投注記錄流水號');
            $table->string('reference', 32)->comment('投注記錄編號');
            $table->string('topOrg', 32)->nullable()->comment('TopOrg帳戶');
            $table->string('org', 32)->nullable()->comment('Org組織帳戶');
            $table->string('loginname', 32)->nullable()->comment('玩家帳號');
            $table->string('currency', 10)->nullable()->comment('幣種');
            $table->string('type', 32)->nullable()->comment('類型');
            $table->decimal('amount', 13, 7)->nullable()->comment('投注金額');
            $table->decimal('beforeAmount', 13, 7)->nullable()->comment('投注前餘額');
            $table->decimal('afterAmount', 13, 7)->nullable()->comment('投注後餘額');
            $table->string('gameName')->nullable()->comment('遊戲名稱');
            $table->dateTime('createTime')->comment('投注時間');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });

        Schema::table('ygg_bet_records', function (Blueprint $table) {
            $table->index('reference');
            $table->index('loginname');
            $table->index('gameName');
        });
    }
}