<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateWJackpotRewardItemsTable extends Migration
{
    protected $table_name = 'w_jackpot_reward_items';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if(!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id')->commit('流水号');
                $table->string('name', 50)->commit('奖项名称');
                $table->tinyinteger('level')->default(0)->commit('奖项等級; 1:首奖, 2:二奖, 3:三奖, 4:特別獎, 5:安慰獎');
                $table->decimal('bonus', 18, 4)->default(0)->commit('奖项金额');
                $table->string('prize_name', 45)->default('')->commit('獎項名稱');
                $table->string('memo')->nullable()->commit('备注');
                $table->tinyinteger('status')->default(0)->commit('狀態; 1:啟用, 0:停用');
                $table->timestamp('created_at')->useCurrent()->comment('建立日期');
                $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')->nullable()->commit('更新者id; 0:為system');
            });
            DB::statement("ALTER TABLE `$this->table_name` comment 'w-jackpot奖励项目'");
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
