<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateHaolongBetRecordsTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('haolong_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('login_id')->comment('投注者帳號');
            $table->string('bet_id')->comment('投注記錄編號');
            $table->datetime('bet_time')->comment('投注時間');
            $table->integer('draw_type')->comment('開彩莊家');
            $table->date('draw_date')->comment('開彩日期');
            $table->integer('bet_number')->comment('投注號碼');
            $table->string('bet_position')->comment('投注位置');
            $table->decimal('bet_amount', 10, 2)->comment('投注額');
            $table->decimal('strike', 10, 2)
                ->nullable()
                ->comment('中獎金額');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }
}
