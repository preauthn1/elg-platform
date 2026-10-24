<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class WmAccountAddUserId extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('wm_accounts', function (Blueprint $table) {
            $table->string('user_id', 32)
                ->after('username')
                ->nullable()
                ->comment('避免同一個USERNAME重覆建立的編號(如果需要创建两次, 回应将会是相同, 但 WAPI 仅在第一次使用时生成新玩家。)');
        });
    }
}