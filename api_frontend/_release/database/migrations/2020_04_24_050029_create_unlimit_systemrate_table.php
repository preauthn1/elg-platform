<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateUnlimitSystemrateTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('unlimit_systemrate', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('agent_id')->default(0)->comment('代理ID');
            $table->integer('level')->default(0)->comment('無限代理等級');
            //$table->string('level_name', 50)->comment('無限代理名稱');
            $table->decimal('min_amount', 10, 2)->default(0)->comment('該級距金額下限');
            $table->decimal('max_amount', 10, 2)->default(0)->comment('該級距金額上限');
            $table->decimal('bonus_amount', 10, 2)->default(0)->comment('該級距獎勵金額');
            $table->decimal('rebate_rate', 10, 2)->default(0)->comment('返傭比例');
        });

        DB::table('unlimit_systemrate')->insert(
            array( 'agent_id' => 1, 'level' => 1, 'min_amount' => 0, 'max_amount' => 9999.99, 'bonus_amount' => 30, 'rebate_rate' => 0.3)
        );
        DB::table('unlimit_systemrate')->insert(
            array( 'agent_id' => 1, 'level' => 2, 'min_amount' => 10000, 'max_amount' => 49999.99, 'bonus_amount' => 40, 'rebate_rate' => 0.4)
        );
        DB::table('unlimit_systemrate')->insert(
            array( 'agent_id' => 1, 'level' => 3, 'min_amount' => 50000, 'max_amount' => 99999.99, 'bonus_amount' => 50, 'rebate_rate' => 0.5)
        );
        DB::table('unlimit_systemrate')->insert(
            array( 'agent_id' => 1, 'level' => 4, 'min_amount' => 100000, 'max_amount' => 299999.99, 'bonus_amount' => 60, 'rebate_rate' => 0.6)
        );
        DB::table('unlimit_systemrate')->insert(
            array( 'agent_id' => 1, 'level' => 5, 'min_amount' => 300000, 'max_amount' => 599999.99, 'bonus_amount' => 70, 'rebate_rate' => 0.7)
        );
        DB::table('unlimit_systemrate')->insert(
            array( 'agent_id' => 1, 'level' => 6, 'min_amount' => 600000, 'max_amount' => 999999.99, 'bonus_amount' => 80, 'rebate_rate' => 0.8)
        );
        DB::table('unlimit_systemrate')->insert(
            array( 'agent_id' => 1, 'level' => 7, 'min_amount' => 1000000, 'max_amount' => 1999999.99, 'bonus_amount' => 90, 'rebate_rate' => 0.9)
        );
        DB::table('unlimit_systemrate')->insert(
            array( 'agent_id' => 1, 'level' => 8, 'min_amount' => 2000000, 'max_amount' => 3999999.99, 'bonus_amount' => 100, 'rebate_rate' => 1.0)
        );
        DB::table('unlimit_systemrate')->insert(
            array( 'agent_id' => 1, 'level' => 9, 'min_amount' => 4000000, 'max_amount' => 7999999.99, 'bonus_amount' => 110, 'rebate_rate' => 1.1)
        );
        DB::table('unlimit_systemrate')->insert(
            array( 'agent_id' => 1, 'level' => 10, 'min_amount' => 8000000, 'max_amount' => 0, 'bonus_amount' => 120, 'rebate_rate' => 1.2)
        );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('unlimit_systemrate');
    }
}
