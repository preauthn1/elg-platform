<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateOtaOrdersTable extends Migration
{
    protected $table_name = 'ota_orders';
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
                $table->string('reference_number')->comment('交易編號')->unique();
                $table->integer('member_id')->default(0)->comment('會員系統編號')->index();
                $table->string('token', 80)->default(0)->nullable(false)->comment('Token');
                $table->integer('agent_cash_flow_id')->nullable(false)->default(0)->comment('金流商戶資料編號');
                $table->decimal('amount', 18, 4)->default(0)->comment('交易金額');
                $table->tinyInteger('status')
                    ->default(0)
                    ->comment('狀態 0:訂單建立中,1:訂單建立完成,2:成功,3:失敗');
                $table->string('info', 500)->comment('response data');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
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
