<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateVendorMaintenanceTable extends Migration
{
    protected $table_name = 'vendor_maintenances';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create($this->table_name, function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('vendor_id')->comment('遊戲商名稱');
            $table->tinyInteger('type')->default(1)->comment('關閉類型，1:遊戲跟錢包都關閉、2:關閉遊戲、3：關閉錢包');
            $table->dateTime('start_time')->comment('開始時間');
            $table->dateTime('end_time')->comment('結束時間');
            $table->integer('agent_id')->default(1)->comment('代理ID')->index();
            $table->integer('exclude_agent_id')->default(0)->comment('排除代理ID');
            $table->boolean('status')->comment('狀態0:停用1:啟用');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }
}