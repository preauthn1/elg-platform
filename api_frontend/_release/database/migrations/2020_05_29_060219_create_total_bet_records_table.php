<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTotalBetRecordsTable extends Migration
{
    protected $table_name = 'total_bet_records';

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
                $table->datetime('summary_time')->comment('時間');
                $table->datetime('data_time')->comment('數據時間');
                $table->integer('member_id')->comment('會員系統編號');
                $table->integer('wallet_type_id')->default(0)->comment('錢包類型編號');
                $table->integer('vendor_id')->comment('遊戲商系統編號');
                $table->integer('bet_record_id')->unsigned()->comment('XXX_bet_record.id');
                $table->integer('game_id')->comment('遊戲系統編號');
                $table->string('reference_no', 100)->comment('來源單號');
                $table->decimal('bet', 30, 10)->default(0.0000)->comment('投注金額');
                $table->decimal('valid_bet', 30, 10)->default(0.0000)->comment('有效投注金額');
                $table->decimal('win', 30, 10)->default(0.0000)->comment('贏分金額');
                $table->decimal('win_lose', 30, 10)->default(0.0000)->comment('輸贏金額');
                $table->decimal('commission', 30, 10)->default(0.0000)->comment('佣金');
                $table->decimal('bak1', 30, 10)->default(0.0000)->comment('備用');
                $table->datetime('bet_time')->comment('投注時間(UTC)');
                $table->datetime('payout_time')->comment('派彩、結算時間(UTC)');
                $table->datetime('created_at')->default(DB::raw('CURRENT_TIMESTAMP'))->comment('建立日期');
                $table->datetime('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'))->comment('更新日期');
                $table->integer('admin')->comment('更新者ID');

                //Primary
                $table->primary(
                    ['id', 'vendor_id'],
                    'pk_total_bet_records_primary'
                );

                //Unique
                $table->unique(
                    ['vendor_id', 'bet_record_id'],
                    'uni_total_bet_records_vendor_id_bet_record_id'
                );
                //Index
                $table->index('member_id');
                $table->index('summary_time');
                $table->index('data_time');
                $table->index('vendor_id');
                $table->index('game_id');
                $table->index('wallet_type_id');
            });
            DB::statement("ALTER TABLE `$this->table_name` comment '存放所有遊戲商的投注記錄'");
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
