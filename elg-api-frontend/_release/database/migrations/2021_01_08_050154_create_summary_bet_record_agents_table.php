<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSummaryBetRecordAgentsTable extends Migration
{
    protected $table_name = 'summary_bet_record_agents';
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
                $table->decimal('total_bet', 18, 4)->default(0)->comment('總投注');
                $table->decimal('total_win_lose', 18, 4)->default(0)->comment('總輸贏');
                $table->decimal('total_v_jackpot', 18, 4)->default(0)->comment('總VJackpot');
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
        Schema::dropIfExists($this->table_name);
    }
}
