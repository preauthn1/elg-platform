<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateVendorInfosTable extends Migration
{
    protected $table_name = 'vendor_infos';
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
                $table->string('name', 32)->comment('方便識別的名稱');
                $table->tinyInteger('type')->comment('遊戲商環境，0:測試，1:正式');
                $table->integer('vendor_id')->comment('遊戲商系統編號');
                $table->integer('wallet_type_id')->default(1)->comment('錢包類型ID');
                $table->string('currency', 5)->comment('幣種代碼');
                $table->decimal('rate', 11, 5)->default(1)->comment('轉換率');
                $table->decimal('transfer_min_amount', 11, 5)
                    ->default(0.01)->comment('最小轉入金額');
                $table->text('info')->comment('連接資訊');
                $table->integer('prepared_account_number')->comment('預備帳號數量');
                $table->string('account_prefix', 10)->comment('帳號前綴');
                $table->decimal('cost', 10, 4)->defaut(0)->comment('成本');
                $table->boolean('status')->default(0)->comment('狀態，0:停用、1:啟用');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')->comment('更新者ID');

                //index
                $table->index('vendor_id');
                $table->index('wallet_type_id');
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