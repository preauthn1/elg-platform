<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class TransactionLogAddOperatorColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('transaction_logs', function (Blueprint $table) {
            $table->string('transaction_no')
                ->after('id')
                ->comment('單號');
            $table->string('operator')
                ->after('result')
                ->comment('操作者');
        });
    }
}