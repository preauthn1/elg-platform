<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateHaolongDailyWinLossesTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('haolong_daily_win_losses', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->date('draw_date')->comment('開彩日期');
            $table->string('login_id')->comment('投注者帳號');
            $table->decimal('turnover', 10, 2)->comment('投注金額');
            $table->decimal('strike', 10, 2)->comment('中獎金額');
            $table->decimal('commission', 10, 2)->comment('佣金');
            $table->decimal('total', 10, 2)->comment('輸贏金額');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
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
        Schema::dropIfExists('haolong_daily_win_losses');
    }
}
