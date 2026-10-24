<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAgentWalletTable extends Migration
{

    protected $table_name = 'agent_wallets';

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
                $table->integer('agent_group_id')->default(0)->comment('agent_groups.id')->index();
                $table->integer('wallet_type_id')->default(0)->comment('錢包幣型系統編號(wallet_types)')->index();
                $table->decimal('rate', 30, 10)->default(1)->comment('與平台幣轉換匯率');
                $table->text('info')->nullable()->comment('相關資訊(json)');
                $table->smallInteger('status')->default(0)->comment('狀態: 0:停用, 1:啟用');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')->default(0)->commit('更新者ID');

                //unique
                $table->unique(['agent_group_id', 'wallet_type_id']);
            });

            DB::statement("ALTER TABLE `$this->table_name` comment '代理支援錢包種類'");
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
