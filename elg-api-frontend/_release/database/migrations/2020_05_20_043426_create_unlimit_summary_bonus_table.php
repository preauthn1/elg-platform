<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateUnlimitSummaryBonusTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('unlimit_summary_bonus', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('member_id')->default(0)->comment('玩家編號');
            $table->decimal('performance_amount', 18, 4)->default(0.0000)->comment('業績金額');
            $table->decimal('bonus_amount', 18, 4)->default(0.0000)->comment('未提領獎金');
            $table->decimal('withdraw_amount', 18, 4)->default(0.0000)->comment('已提領獎金');
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
        Schema::dropIfExists('unlimit_summary_bonus');
    }
}
