<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateIaBetRecordTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ia_bet_records', function (Blueprint $table)
        {
            $table->increments('id')->comment('系統編號');
            $table->string('rowID')->comment('唯一id');
            $table->string('activity_id')->comment('活動id');
            $table->decimal('betamount', 13, 7)->comment('投注金額');
            $table->decimal('winamount', 13, 7)->comment('贏錢金額');
            $table->string('money_type')->comment('金錢類型，1=>現金，2=>虛擬幣');
            $table->string('currency_id')->comment('幣種id');         
            $table->string('exchange_rate')->comment('換人民幣匯率');
            $table->string('show_scale')->comment('幣種顯示縮放比例');
            $table->string('desc')->comment('描述');
            $table->string('create_time')->comment('生成時間');
            $table->string('username')->comment('用戶名');
            $table->string('userip')->comment('下注ip');
            $table->string('cdnip')->comment('下注的cdnip');
            $table->string('client_type')->comment('客戶使用的客戶端 1-pc 2-wap 3-app');
            $table->string('order_id')->comment('活動注單號');
            $table->string('activity_name')->comment('活動名稱');
            $table->string('utc_create_time')->nullable()->comment('遊戲時間(UTC+0)');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ia_bet_records');
    }
}
