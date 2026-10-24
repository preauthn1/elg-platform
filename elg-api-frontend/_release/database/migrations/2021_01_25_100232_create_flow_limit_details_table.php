<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateFlowLimitDetailsTable extends Migration
{
    protected $table_name = 'flow_limit_details';

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
                $table->integer('flow_limit_id')->comment('flow_limits.id');
                $table->integer('start_total_bet_record_id')->comment('該筆稽核流水啟始ID');
                $table->datetime('end_total_bet_record_id')->nullable()->comment('該筆稽核流水結束D');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
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
        Schema::dropIfExists('flow_limit_details');
        Schema::dropIfExists($this->table_name);
    }
}
