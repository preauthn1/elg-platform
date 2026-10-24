<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertVendorLucky extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('vendors')->insert(array(
            'id' => 12,
            'name' => 'Lucky',
            'image' => '',
            'rate' => 1,
            'percentage' => 0,
            'need_login' => 0,
            'status' => 0,
            'sort' => 99,
            'admin' => 1
        ));

        Schema::create('lucky_accounts', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('username')->comment('lucky帳號');
            $table->string('player_id')->nullable()
                ->comment('玩家帳號');
            $table->decimal('balance', 10, 2)
                ->default(0)
                ->comment('lucky餘額');
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
    }
}