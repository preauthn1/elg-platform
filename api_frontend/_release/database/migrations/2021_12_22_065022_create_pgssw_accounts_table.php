<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreatePgsswAccountsTable extends Migration
{
    protected $table_name = 'pgssw_accounts';
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
                $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
                $table->string('username')->comment('玩家帳號');
                $table->integer('member_id')->default(0)->comment('會員系統編號');
                $table->string('session_token', 50)->comment('登入token');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')->default(0)->commit('更新者ID');

                //Index
                $table->index('vendor_info_id');
                $table->index('username');
                $table->index('member_id');
                $table->index('session_token');
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
