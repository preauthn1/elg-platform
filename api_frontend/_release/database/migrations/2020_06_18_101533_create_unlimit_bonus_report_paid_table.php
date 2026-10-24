<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateUnlimitBonusReportPaidTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('unlimit_bonus_report_paids', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('member_id')->comment('玩家ID');
            $table->datetime('summary_date')->comment('統計時間');
            $table->decimal('performance_amount', 18,4)->default(0)->comment('績效');
            $table->decimal('bonus_amount', 18,4)->default(0)->comment('獎勵');
            $table->tinyInteger('status')->default(0)->comment('狀態; 0:未發放, 1:已發放');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');

            //index
            $table->index('member_id');
            $table->index('summary_date');
            $table->index('status');

            //unique
            $table->unique(['member_id', 'summary_date']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('unlimit_bonus_report_paids');
    }
}
