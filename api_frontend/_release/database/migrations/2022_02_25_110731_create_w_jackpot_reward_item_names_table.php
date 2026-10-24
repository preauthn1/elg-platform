<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateWJackpotRewardItemNamesTable extends Migration
{
    protected $table_name = 'w_jackpot_reward_item_names';

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
                $table->integer('language_id')->commit('语言系统编号');
                $table->integer('w_jackpot_reward_item_id')->commit('奖励类型系统编号');
                $table->string('name', 50)->commit('奖励类型名称');
                $table->string('prize_name', 50)->commit('奖励类型名称');
                $table->timestamp('created_at')->useCurrent()->comment('建立日期');
                $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')->default(0)->commit('更新者ID');
            });
            DB::statement("ALTER TABLE `$this->table_name` comment 'wjackpot抽獎券'");
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
