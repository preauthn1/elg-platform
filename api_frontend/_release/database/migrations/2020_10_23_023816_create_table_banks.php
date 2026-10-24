<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTableBanks extends Migration
{
    protected $table_name = 'banks';

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
                $table->integer('agent_group_id')->nullable(false)->default(0)->comment('代理群組編號');
                $table->string('name', 50)->comment('銀行名稱');
                $table->string('code', 16)->comment('銀行代碼');
                $table->string('third_party_code', 20)->nullable()->comment('三方銀行代碼');
                $table->integer('sort')->default(0)->comment('排序');
                $table->tinyInteger('status')->comment('狀態');
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
