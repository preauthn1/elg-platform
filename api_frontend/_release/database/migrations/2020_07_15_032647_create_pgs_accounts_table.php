<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreatePgsAccountsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pgs_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號')->index();
            $table->string('username')->comment('玩家帳號')->index();
            $table->decimal('balance', 18, 4)
                ->default(0)
                ->comment('玩家餘額');
            $table->integer('member_id')
                ->default(0)
                ->comment('會員系統編號')
                ->index();
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pgs_accounts');
    }
}
