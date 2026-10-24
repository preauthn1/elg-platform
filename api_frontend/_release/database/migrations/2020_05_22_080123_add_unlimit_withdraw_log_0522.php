<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddUnlimitWithdrawLog0522 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('unlimit_withdrawlog', function (Blueprint $table) {
            $table->string('order_no', 10)
            	->after('id')
                ->comment('訂單單號');
            $table->decimal('reward_point', 18, 2)
            	->after('member_id')
            	->default(0.00)
                ->comment('申請前結算金額');
            $table->decimal('withdrawn_point', 18, 2)
            	->after('reward_point')
            	->default(0.00)
                ->comment('已提領金額');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    	Schema::table('unlimit_withdrawlog', function (Blueprint $table) {
    		$table->dropColumn('order_no');
    		$table->dropColumn('reward_point');
    		$table->dropColumn('withdrawn_point');
		});
    }
}
