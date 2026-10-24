<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateScheduleLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('schedule_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('schedule_name')->index();
            $table->text('input');
            $table->integer('exit_code')->index();
            $table->dateTime('start_time')->index();
            $table->dateTime('end_time');
            $table->decimal('usages')->index();
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
        Schema::dropIfExists('schedule_logs');
    }
}
