<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateTableOppoAccounts extends Migration
{
    private $table_name = 'oppo_accounts';

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
                $table->integer('vendor_info_id')->comment('商戶編號')->index();
                $table->string('username', 32)->comment('玩家帳號');
                $table->string('player_id')->default('')->comment('三方帳號')->index();
                $table->string('password', 32)->default('')->comment('玩家密碼');
                $table->integer('member_id')->default(0)->comment('會員系統編號')->index();
                $table->integer('admin')->default(0)->comment('管理員編號');
                $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));

                $table->unique('username');
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
