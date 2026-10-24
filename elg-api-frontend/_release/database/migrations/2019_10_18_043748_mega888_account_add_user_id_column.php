<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class Mega888AccountAddUserIdColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('mega888_accounts', function (Blueprint $table) {
            $table->string('user_id')
                ->after('player_id')
                ->nullable()
                ->comment('Mega888系統內部分配id(登入帳號)');
        });
    }
}