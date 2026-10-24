<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddUnlimitWithdrawLogColumn extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('unlimit_withdrawlog', function ($table) {
            $table->tinyInteger('type')
                ->default(0)
                ->comment('支付類型。 0:未發放, 1:已發放、2:拒絕');
            $table->string('remark')
                ->nullable()
                ->comment('備註');
            $table->Integer('admin')
                ->nullable()
                ->comment('操作人員');
        });
        
        //遊戲
        Schema::table('games', function ($table) {
            $table->decimal('valid_bet_rate', 10, 2)
                ->nullable()
                ->default(1.00)
                ->comment('有效投注轉換比例');
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
