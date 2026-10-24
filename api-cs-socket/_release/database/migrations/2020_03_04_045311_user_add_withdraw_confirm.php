<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UserAddWithdrawConfirm extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->integer('withdraw_confirm')
                ->after('transaction_type')
                ->default(1)
                ->comment('洗分是否需要同意，0:不需要、1:需要');
        });
    }
}