<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSummaryBetRecordsTable extends Migration
{
    protected $table_name = 'summary_bet_records';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id')->comment('流水號');
                $table->dateTime('summary_time')->comment('時間');
                $table->dateTime('data_time')->comment('數據時間');
                $table->integer('member_id')->comment('會員系統編號');
                $table->integer('wallet_type_id')->default(0)->comment('錢包類型編號');
                $table->integer('vendor_id')->comment('遊戲商系統編號');
                $table->string('game_id')->default(0)->comment('遊戲系統編號');
                $table->decimal('bet', 30, 10)->default(0)->comment('投注金額');
                $table->decimal('win_lose', 30, 10)->default(0)->comment('贏得金額');
                $table->decimal('commission', 30, 10)->default(0)->comment('佣金');
                $table->decimal('jackpot', 30, 10)->default(0)->comment('彩金');
                $table->decimal('percentage')->comment('分成百分比');
                $table->decimal('share_amount')->comment('分成金額');
                $table->string('share_range')->comment('分成區間');
                $table->decimal('v_jackpot', 30, 10)->default(0)->comment('v_jackpot');
                $table->decimal('cost', 30, 10)->default(0)->comment('成本');
                $table->tinyInteger('correction')->default(0)->comment('改正標示');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')
                    ->default(0)
                    ->comment('更新者ID');

                //Index
                $table->index('summary_time');
                $table->index('data_time');
                $table->index('member_id');
                $table->index('wallet_type_id');
                $table->index('vendor_id');
                $table->index('game_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists($this->table_name);
    }
}