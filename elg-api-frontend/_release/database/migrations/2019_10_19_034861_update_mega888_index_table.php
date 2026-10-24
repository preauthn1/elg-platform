<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateMega888IndexTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('mega888_accounts', function (Blueprint $table) {
            $table->unique('username');
            $table->index('player_id');
            $table->index('member_id');
        });

        Schema::table('mega888_daily_wins', function (Blueprint $table) {
            $table->unique(['date', 'type', 'loginId']);
            $table->index('date');
            $table->index('loginId');
            $table->index('type');
        });
    }
}