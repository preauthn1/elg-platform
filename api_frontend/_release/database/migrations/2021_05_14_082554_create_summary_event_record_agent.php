<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSummaryEventRecordAgent extends Migration
{
    protected $table_name = 'summary_event_record_agents';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->datetime('summary_time')->comment('數據時間');
                $table->integer('agent_id')->comment('代理編號');
                $table->tinyInteger('event_id')->comment('事件編號; 1:活動獎勵(含登入活動), 2:無限代獎勵,3:pay fee, 4:sms fee');
                $table->decimal('point', 18, 4)->default(0)->comment('點數');
                $table->decimal('self_point', 18, 4)->default(0)->comment('代理本身所獲得點數');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');

                $table->primary(
                    array('summary_time', 'agent_id', 'event_id'),
                    'pk_summary_event_record_agents_primary'
                );
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
