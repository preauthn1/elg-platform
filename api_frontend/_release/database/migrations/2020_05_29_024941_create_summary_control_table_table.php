<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSummaryControlTableTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
    	$table_name = 'summary_control_tables';
        Schema::create($table_name, function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('vendor_id')->nullable()->comment('遊戲商編號');
            $table->string('vendor_code', 32)->nullable()->comment('遊戲商代碼');
            $table->datetime('stat_start_time')->nullable()->comment('統計開始時間');
            $table->datetime('stat_end_time')->nullable()->comment('統計結束時間');
            $table->datetime('exec_start_time')->nullable()->comment('執行開始時間');
            $table->datetime('exec_end_time')->nullable()->comment('執行結束時間');
        });
        DB::statement("ALTER TABLE `$table_name` comment '彙整排程時間控制表'");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('summary_control_tables');
    }
}
