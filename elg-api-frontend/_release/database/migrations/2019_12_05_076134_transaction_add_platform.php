<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class TransactionAddPlatform extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('transaction_logs', function (Blueprint $table) {
            $table->integer('platform')
                ->after('operator')
                ->default(0)
                ->comment('操作平台0:前台，1:客服，2:代理後台');
        });

        Schema::table('transaction_log_agents', function (Blueprint $table) {
            $table->integer('platform')
                ->after('operator')
                ->default(1)
                ->comment('操作平台1:客服，2:代理後台');
        });
    }
}