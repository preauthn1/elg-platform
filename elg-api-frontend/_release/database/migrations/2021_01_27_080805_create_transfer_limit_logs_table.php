<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTransferLimitLogsTable extends Migration
{
    protected $table_name = 'transfer_limit_logs';

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
                $table->integer('agent_group_id')->nullable(false)->default(0)->comment('代理群組編號')->index();
                $table->decimal('amount', 18, 4)->comment('變更限額');
                $table->decimal('before_limit', 18, 4)->comment('變更前限額');
                $table->decimal('after_limit', 18, 4)->comment('變更後限額');
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
