<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AgentAddApiInfoColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('CS_API_USER')
                ->after('percentage')
                ->nullable()
                ->comment('與客服系統串接的帳號');
            $table->string('CS_API_KEY')
                ->after('CS_API_USER')
                ->nullable()
                ->comment('與客服系統串接的KEY');
        });
    }
}