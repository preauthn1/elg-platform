<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterWithdrawalOrder20210115 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('withdrawal_orders', function($table) {            
            $table->integer('operator')
                ->default(0)
                ->comment('審核人');
                
            $table->text('memo')
                ->comment('備註')
                ->change();
            
            $table->text('change_logs')
                ->comment('修改歷程');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}