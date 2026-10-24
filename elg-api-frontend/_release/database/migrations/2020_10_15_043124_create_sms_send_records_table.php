<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSmsSendRecordsTable extends Migration
{
    protected $table_name = 'sms_send_records';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable($this->table_name)){
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id')->comment('流水號');
                $table->integer('sms_gateway_id')->default(0)->comment('簡訊商ID(sms_gateways.id)');
                $table->integer('member_id')->default(0)->unsigned()->comment('玩家ID');
                $table->string('mobile', 20)->comment('發送號碼');
                $table->string('message_content', 100)->comment('簡訊發送內容');
                $table->string('transaction_id', 50)->nullable()->comment('簡訊商發送ID');
                $table->text('response_message')->nullable()->comment('簡訊商response data(json)');
                $table->integer('status')->default(0)->comment('發送狀態;0:未發送,1:發送成功,2:發送失敗');
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
