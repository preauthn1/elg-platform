<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateLuckyDrawTable extends Migration
{

    protected $table_name = "lucky_draws";

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if(!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id')->comment('流水号');
                $table->integer('event_id')->default(0)->comment('活動ID');
                $table->integer('no')->default(0)->comment('期數');
                $table->datetime('start_time')->comment('開始時間');
                $table->datetime('end_time')->comment('結束時間');
                $table->datetime('draw_time')->nullable()->comment('開獎時間');
                $table->string('draw_number', 20)->nullable()->comment('開獎號碼');
                $table->string('remark', 100)->nullable()->comment('備註');
                $table->integer('status')->default(0)->comment('狀態; 0:未開獎, 1:已開奬, 2:已開未派獎, 3:已開派獎中, 4:取消');
                $table->timestamp('created_at')->useCurrent()->comment('建立日期');
                $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')->default(0)->commit('更新者ID');
            });
            DB::statement("ALTER TABLE `$this->table_name` comment '抽獎券活動明細'");
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
