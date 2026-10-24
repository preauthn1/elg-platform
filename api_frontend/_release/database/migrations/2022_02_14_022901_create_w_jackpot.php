<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateWJackpot extends Migration
{
    protected $table_name = 'w_jackpots';

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
                $table->integer('event_id')->default(0)->comment('events.id')->index();
                $table->integer('event_detail_id')->default(0)->comment('event_details.id')->index();
                $table->integer('no')->default(0)->comment('期數');
                $table->datetime('draw_time')->nullable()->comment('開獎時間');
                $table->string('draw_number', 4)->nullable()->comment('開獎號碼1');
                $table->string('draw_number_2', 4)->nullable()->comment('開獎號碼2');
                $table->string('draw_number_3', 4)->nullable()->comment('開獎號碼3');
                $table->string('draw_number_4', 200)->nullable()->comment('開獎號碼4');
                $table->string('draw_number_5', 200)->nullable()->comment('開獎號碼5');
                $table->string('remark', 100)->nullable()->comment('備註');
                $table->integer('status')->default(0)->comment('狀態; 0:未開獎, 1:已開奬, 2:已開未派獎, 3:已開派獎中, 4:取消');
                $table->timestamp('created_at')->useCurrent()->comment('建立日期');
                $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')->default(0)->commit('更新者ID');
            });
            DB::statement("ALTER TABLE `$this->table_name` comment 'w-jackpot抽獎券活動明細'");
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
