<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSummaryPointBarrelsTable extends Migration
{
    protected $table_name = 'summary_point_barrels';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id')->comment('流水號');
                $table->dateTime('summary_time')->comment('彙總時間(UTC)');
                $table->integer('agent_id')->comment('代理ID');
                $table->integer('parent_id')->comment('父代理ID');
                $table->integer('vendor_id')->comment('遊戲商ID');
                $table->string('hierarchy_path')->comment('代理階層路徑');
                $table->integer('share_point')->comment('分桶分成比例');
                $table->decimal('total_turnover', 18, 4)->default(0)->comment('總有效流水');
                $table->decimal('total_nett_profit', 18, 4)->default(0)->comment('總淨利潤');
                $table->decimal('self_turnover', 18, 4)->default(0)->comment('自己有效流水');
                $table->decimal('self_nett_profit', 18, 4)->default(0)->comment('自己淨利潤');
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
