<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateUnlimitBonusReportTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('unlimit_bonusreport', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('member_id')->default(0)->comment('玩家編號');
            $table->datetime('summary_date')->comment('時間(日)');
            $table->decimal('performance', 18, 4)->default(0.0000)->comment('業績-自己');
            $table->decimal('performance_direct', 18, 4)->default(0.0000)->comment('業績-直屬');
            $table->decimal('performance_team', 18, 4)->default(0.0000)->comment('業績-團隊');
            $table->decimal('bonus_direct', 18, 4)->default(0.0000)->comment('獎金-直屬');
            $table->decimal('bonus_team', 18, 4)->default(0.0000)->comment('獎金-團隊');
            $table->decimal('bonus_amount', 10, 2)->default(0.00)->comment('該級距獎勵金額-參考unlimit_systemrate');
            $table->timestamps();
            $table->integer('admin')->default(0)->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('unlimit_bonusreport');
    }
}
