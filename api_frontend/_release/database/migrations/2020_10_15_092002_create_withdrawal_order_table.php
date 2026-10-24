<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateWithdrawalOrderTable extends Migration
{
    protected $table_name = 'withdrawal_orders';

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
                $table->string('bank_name', 50)->comment('銀行名稱');
                $table->string('bank_code', 16)->comment('銀行代碼');
                $table->string('account_name', 50)->comment('帳戶名稱');
                $table->string('account_no', 50)->comment('銀行帳戶');
                $table->integer('agent_group_id')->nullable(false)->default(0)->comment('金流分組編號')->index();
                $table->integer('member_id')->comment('帳號系統編號(Members)');
                $table->decimal('amount', 18, 4)->comment('金額');
                $table->string('third_party_order_no', 50)->comment('三方金流訂單編號');
                $table->text('third_party_response')->nullable()->comment('三方API的response');
                $table->tinyInteger('status')->nullable(false)->default(0)->comment('狀態 0:訂單建立,1:待審核,2:失敗,3:通過,4:已轉帳');
                $table->dateTime('check_time')->nullable()->comment('審核時間');
                $table->dateTime('payment_time')->nullable()->comment('付款時間');
                $table->text('memo')->nullable()->comment('備註');
                $table->integer('operator')->default(0)->comment('審核人');
                $table->text('change_logs')->comment('修改歷程');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')->default(0)->commit('更新者ID');
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
