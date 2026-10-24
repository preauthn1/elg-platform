<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateEventSlotCompetitionsTable extends Migration
{
    protected $table_name = 'event_slot_competitions';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id');
                $table->integer('event_id')->default(0)->comment('events.id');
                $table->integer('event_detail_id')->default(0)->comment('event_details.id');
                $table->integer('no')->default(0)->comment('期數');
                $table->datetime('draw_time')->nullable()->comment('派發時間');
                $table->string('remark', 100)->nullable()->comment('備註');
                $table->tinyInteger('status')->default(0)->comment('狀態 0:未派獎,1:已派獎,2:派獎中');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')->default(0)->commit('更新者ID');
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
