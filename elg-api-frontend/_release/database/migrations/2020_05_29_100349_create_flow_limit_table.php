<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateFlowLimitTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
    	$table_name = 'flow_limits';
        Schema::create($table_name, function (Blueprint $table) {
            $table->increments('id');
            $table->integer('member_id')->comment('玩家ID');
            $table->integer('type')->comment('參照類型: 1:transaction_logs');
            $table->integer('reference_id')->comment('參照ID; 依照type');
            $table->decimal('flow_limit', 18, 4)->comment('流水限制');
            $table->decimal('reach', 18, 4)->default(0.0000)->comment('達成');
            $table->string('remark', 100)->nullable()->comment('備註');
            $table->integer('status')->default(0)->comment('狀態; 0:未通過, 1:通過');
            $table->datetime('created_at')->default(DB::raw('CURRENT_TIMESTAMP'))->comment('建立日期');
            $table->datetime('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'))->comment('更新日期');
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
        Schema::dropIfExists('flow_limits');
    }
}
