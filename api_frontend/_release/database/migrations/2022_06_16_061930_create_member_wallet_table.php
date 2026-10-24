<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMemberWalletTable extends Migration
{

    protected $table_name = 'member_wallets';

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
                $table->integer('member_id')->comment('玩家系統編號(Members.id)')->index();
                $table->integer('wallet_type_id')->comment('錢包類型系統編號(wallet_types)')->index();
                $table->decimal('balance', 30, 10)->default(0)->comment('錢包實際金額');
                $table->dateTime('first_deposit')->nullable()->comment('首次儲值時間');
                $table->smallInteger('status')->default(0)->comment('狀態: 0:停用, 1:啟用');
                $table->string('memo')->nullable()->comment('方便技術記錄用');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')->default(0)->commit('更新者ID');

                //unique
                $table->unique(['member_id', 'wallet_type_id']);
            });

            DB::statement("ALTER TABLE `$this->table_name` comment '玩家錢包'");
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
