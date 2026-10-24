<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AgentAddTransactionAll extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->boolean('transaction_all')
                ->after('has_app')
                ->default(0)
                ->comment('進入遊戲時是否將所有錢轉入，0:否1:是');
        });
    }
}