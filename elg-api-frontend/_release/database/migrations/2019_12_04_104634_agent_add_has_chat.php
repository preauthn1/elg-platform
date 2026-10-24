<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AgentAddHasChat extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->boolean('has_chat')
                ->after('frontend_register')
                ->default(0)
                ->comment('是否開放聊天室');
            $table->boolean('has_pc')
                ->after('has_chat')
                ->default(0)
                ->comment('是否有PC版');
            $table->boolean('has_mobile')
                ->after('has_pc')
                ->default(0)
                ->comment('是否有手機版');
            $table->boolean('has_online_payment')
                ->after('has_mobile')
                ->default(0)
                ->comment('是否有線上儲值');
            $table->boolean('has_app')
                ->after('has_online_payment')
                ->default(0)
                ->comment('是否有APP');
        });
    }
}