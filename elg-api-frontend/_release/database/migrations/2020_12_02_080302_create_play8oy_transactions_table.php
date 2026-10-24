<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreatePlay8oyTransactionsTable extends Migration
{
    protected $table_name = 'play8oy_transactions';

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
                $table->string('transaction_id')
                    ->default('')
                    ->comment('外部交易Id(OperationMethod＝3此欄位為空值)');
                $table->integer('type')
                    ->comment('類型; OperationMethod; 1:開分,2:洗分,3:紅包(福袋、新年禮物)')
                    ->index();
                $table->string('username', 30)->comment('玩家帳號');
                $table->decimal('trans_amount', 18,4)->comment('交易金額');
                $table->datetime('transaction_time')->comment('交易時間(utc)');
                $table->integer('status')
                    ->comment('狀態; Result 0:未處理,1:成功,2:失敗')
                    ->index();
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')->default(0)->comment('最後修改人員');
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
