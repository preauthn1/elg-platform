<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateRewardItemTable extends Migration
{
    protected $table_name = "reward_items";

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
                $table->tinyinteger('level')->default(0)->commit('奖项等級; 1:终极彩池, 2:首奖, 3:二奖, 4:三奖');
                $table->decimal('bonus', 18, 4)->default(0)->commit('奖项金额');
                $table->string('memo')->nullable()->commit('备注');
                $table->timestamp('created_at')->useCurrent()->comment('建立日期');
                $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')->nullable()->commit('更新者id; 0:為system');
            });

            DB::statement("ALTER TABLE `$this->table_name` comment '奖励项目'");
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
