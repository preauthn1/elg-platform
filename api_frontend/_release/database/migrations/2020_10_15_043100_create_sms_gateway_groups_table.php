<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSmsGatewayGroupsTable extends Migration
{
    protected $table_name = 'sms_gateway_groups';

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
                $table->integer('organization_id')->default(0)->comment('組織ID');
                $table->string('name', 45)->comment('簡訊群組名稱');
                $table->string('memo', 200)->comment('備註');
                $table->string('setting', 200)->default('{}')
                    ->comment('簡訊商資訊, json格式; {"token_expired":"token有效時間", "max_send":"有效時間最大發送次數"}');
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
