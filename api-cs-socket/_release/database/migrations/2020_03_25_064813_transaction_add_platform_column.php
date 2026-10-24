<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class TransactionAddPlatformColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {

        Schema::table('transactions', function (Blueprint $table) {
            $table->tinyInteger('platform')
                ->after('type')
                ->default(1)
                ->comment('類型(預設1:平台)1:平台，2:遊戲商');
        });
    }
}