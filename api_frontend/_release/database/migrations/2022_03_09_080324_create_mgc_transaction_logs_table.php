<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMgcTransactionLogsTable extends Migration
{
    protected $table_name = 'mgc_transaction_logs';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id');
                $table->string('order_id')->default(0)->comment('transaction_log.id')->unique();
                $table->string('trade_id')->default(0)->comment('注单唯一Id, ThemeId')->unique();
                $table->integer('vendor_info_id')->nullable(false)->default(0)->comment('遊戲商設定編號');
                $table->integer('type')->default(0)->comment('類別 0:轉回平台,1:轉入遊戲商');
                $table->string('username', 30)->comment('玩家帳號');
                $table->decimal('money', 18, 4)->default(0)->comment('轉帳金額');
                $table->integer('time')->default(0)->comment('已使用api的次數');
                $table->tinyInteger('status')->default(0)->comment('狀態 0:未處理,1:已處理');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期')
                    ->index();
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
