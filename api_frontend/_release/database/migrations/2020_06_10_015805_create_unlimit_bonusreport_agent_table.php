<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateUnlimitBonusreportAgentTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('unlimit_bonusreport_agents', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->default(0)->comment('代理編號');
            $table->datetime('summary_date')->comment('時間(日)');
            $table->decimal('performance_direct', 18, 4)->default(0.0000)->comment('業績-直屬');
            $table->decimal('performance_team', 18, 4)->default(0.0000)->comment('業績-團隊');
            $table->decimal('bonus_direct', 18, 4)->default(0.0000)->comment('獎金-直屬');
            $table->decimal('bonus_team', 18, 4)->default(0.0000)->comment('獎金-團隊');
            $table->decimal('bonus_amount', 10, 2)->default(0.00)->comment('該級距獎勵金額-參考unlimit_systemrate');
            $table->datetime('created_at')->default(DB::raw('CURRENT_TIMESTAMP'))->comment('建立日期');
            $table->datetime('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'))->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('unlimit_bonusreport_agents');
    }
}
