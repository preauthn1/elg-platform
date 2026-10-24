<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSummaryPointBarrelWinloseTable extends Migration
{
    protected $table_name = 'summary_point_barrel_winloses';
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
                $table->dateTime('summary_time')->comment('彙總時間(UTC)');
                $table->integer('agent_id')->comment('代理ID');
                $table->integer('vendor_id')->comment('遊戲商ID');
                $table->decimal('turnover', 18, 4)->default(0)->comment('營業額');
                $table->decimal('game_spending', 18, 4)->default(0)->comment('遊戲支出');
                $table->decimal('game_profit', 18, 4)->default(0)->comment('遊戲盈利');
                $table->string('memo', 18, 4)->comment('備註');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')->default(0)->commit('更新者ID');
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
        Schema::dropIfExists('summary_point_barrel_winlose');
        Schema::dropIfExists($this->table_name);
    }
}
