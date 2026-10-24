<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateIaAccountsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ia_accounts', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
            $table->string('username')->comment('玩家帳號');;
            $table->string('password')->comment('玩家密碼');
            $table->decimal('balance', 10, 2)
                ->default(0)
                ->comment('玩家餘額');
            $table->integer('member_id')
                ->default(0)
                ->comment('會員系統編號');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
        Schema::table('ia_accounts', function (Blueprint $table) {
            $table->index('member_id');
        });
    }
}
