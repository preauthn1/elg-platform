<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSmsGatewayDetailsTable extends Migration
{
    protected $table_name = 'sms_gateway_details';
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
                $table->integer('sms_gateway_group_id')->default(0)->comment('簡訊群組ID(sms_gateway_groups.id)');
                $table->integer('sms_gateway_id')->default(0)->unsigned()->comment('簡訊商ID');
                $table->string('info', 200)->default('{}')->comment('簡訊商商戶資訊, 內容使用json儲存');
                $table->integer('sort')->default(0)->comment('簡訊商排序');
                $table->integer('status')->default(0)->comment('狀態; 0:停用, 1:啟用');
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
