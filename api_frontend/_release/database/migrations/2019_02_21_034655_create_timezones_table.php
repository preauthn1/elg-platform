<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTimezonesTable extends Migration
{
    protected $table_name = 'timezones';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id')->comment('系統編號');
                $table->string('time_offset', 15)->comment('偏移時間ex:UTC+12:00');
                $table->tinyInteger('is_summer')->default(0)->comment('是否為夏令時');
                $table->date('summer_start')->comment('夏令時開始日期');
                $table->date('summer_end')->comment('夏令時結束日期');
                $table->tinyInteger('status')->default(0)->comment('狀態 0:停用,1:啟用');
                $table->timestamp('created_at')->useCurrent()->comment('建立日期');
                $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))->comment('更新日期');
                $table->integer('admin')->comment('更新者ID');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists($this->table_name);
    }
}
