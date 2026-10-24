<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddVendorAccountColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // AG帳號
        Schema::table('ag_accounts', function (Blueprint $table) {
            $table->integer('vendor_id')
                ->after('balance')
                ->default(0)
                ->comment('營運商編號');
        });
        // Joker帳號
        Schema::table('joker_accounts', function (Blueprint $table) {
            $table->integer('vendor_id')
                ->after('balance')
                ->default(0)
                ->comment('營運商編號');
        });

        // 豪龍帳號
        Schema::table('haolong_accounts', function (Blueprint $table) {
            $table->integer('vendor_id')
                ->after('balance')
                ->default(0)
                ->comment('營運商編號');
        });
        // GW99帳號
        Schema::table('great_wall_accounts', function (Blueprint $table) {
            $table->integer('vendor_id')
                ->after('balance')
                ->default(0)
                ->comment('營運商編號');
        });
        // 營運商設定自己的遊戲商資訊
        Schema::create('agent_vendors', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('agent_id')->comment('營運商系統編號');
            $table->integer('vendor_id')->comment('遊戲商系統編號');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商資訊編號');
            $table->boolean('status')->default(0)->comment('狀態');
            $table->integer('backend_show')->default(0)->comment('後台顯示');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
        // 金流商資料
        Schema::create('cash_flows', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('name')->comment('金流商名稱');
            $table->decimal('rate', 11, 5)->default(1);
            $table->decimal('percentage', 11, 5)->default(0);
            $table->string('info')->comment('API使用資訊');
            $table->boolean('status')->comment('狀態0:停用,1:啟用');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
        // 營運商設定自己的金流商資訊
        Schema::create('agent_cash_flows', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('cash_flow_id')->comment('金流商系統編號');
            $table->integer('agent_group_id')->nullable(false)->default(0)->comment('代理群組編號')->index();
            $table->decimal('rate', 10, 4)->nullable(false)->default(0)->comment('轉換比例');
            $table->text('info')->comment('API使用資訊');
            $table->tinyInteger('status')->nullable(false)->default(0)->comment('設置啟用狀態 0:停用, 1:啟用')->index();
            $table->integer('sorts')->nullable(false)->default(0)->comment('排序權重')->index();
            $table->decimal('cost', 10, 4)->default(0)->comment('成本');
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
