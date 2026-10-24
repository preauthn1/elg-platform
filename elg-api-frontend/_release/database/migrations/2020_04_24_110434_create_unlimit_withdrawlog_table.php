<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateUnlimitWithdrawlogTable extends Migration
{
    /**
     * Run the migrations.
     * 無限代理提現紀錄 unlimit_withdrawlog
     * @return void
     */
    public function up()
    {
        Schema::create('unlimit_withdrawlog', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('member_id')->unsigned()->comment('玩家編號');
            $table->decimal('amount', 12, 2)->default(0)->comment('提領金額');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('unlimit_withdrawlog');
    }
}
