<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class TransactionAddBalanceColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('transaction_agents', function (Blueprint $table) {
            $table->decimal('from_limit_of_credit', 10, 2)
                ->after('amount')
                ->default(0)
                ->comment('發送者發送後額度');
            $table->decimal('from_used_credit', 10, 2)
                ->after('from_limit_of_credit')
                ->default(0)
                ->comment('發送者發送後使用額度');
            $table->decimal('to_limit_of_credit', 10, 2)
                ->after('from_used_credit')
                ->default(0)
                ->comment('接收者接收後額度');
            $table->decimal('to_used_credit', 10, 2)
                ->after('to_limit_of_credit')
                ->default(0)
                ->comment('接收者接收後使用額度');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('user_limit_of_credit', 10, 2)
                ->after('amount')
                ->default(0)
                ->comment('代理發送後額度');
            $table->decimal('user_used_credit', 10, 2)
                ->after('user_limit_of_credit')
                ->default(0)
                ->comment('代理發送後使用額度');
            $table->decimal('member_balance', 10, 2)
                ->after('user_used_credit')
                ->default(0)
                ->comment('玩家接收後額度');
        });
    }
}