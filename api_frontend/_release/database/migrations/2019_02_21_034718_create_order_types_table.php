<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateOrderTypesTable extends Migration
{
    protected $table_name = 'order_types';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id')->comment('系統編號');
                $table->integer('cash_flow_id')->default(0)->comment('金流商編號');
                $table->string('name', 32)->comment('類別名稱');
                $table->string('key', 16)->comment('支付方式代碼');
                $table->integer('sorts')->nullable(false)->default(0)->comment('排序權重')->index();
                $table->tinyInteger('status')->default(0)->comment('狀態 0:停用,1:啟用');
                $table->text('image_path')->nullable()->comment('圖片路徑');
                $table->timestamp('created_at')->useCurrent()->comment('建立日期');
                $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))->comment('更新日期');
                $table->integer('admin')->comment('更新者ID');
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
