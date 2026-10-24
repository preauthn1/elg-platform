<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateEventSlotCompetitionRecordsTable extends Migration
{
    protected $table_name = 'event_slot_competition_records';

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
                $table->integer('event_slot_competition_id')->default(0)->comment('event_slot_competitions.id');
                $table->integer('member_id')->default(0)->comment('members.id');
                $table->decimal('bet_amount', 18, 4)->default(0)->comment('投注金額');
                $table->datetime('last_bet_time')->nullable()->comment('最後投注時間');
                $table->integer('prize_content_id')->default(0)->comment('獎項ID;prize_contents.id');
                $table->integer('ranking')->default(0)->comment('名次');
                $table->decimal('point', 18, 4)->default(0)->comment('獎勵金額');
                $table->tinyInteger('type')->default(0)->comment('中獎結果; 0:未中, 1:中獎');
                $table->tinyInteger('status')->default(0)->comment('提領結果; 0:未領, 1:已領取');
                $table->datetime('time')->nullable()->comment('領取時間');
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
