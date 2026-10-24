<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddBankAccountColumn0918 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('bank_accounts', 'account_name'))
        {
            Schema::table('bank_accounts', function (Blueprint $table) {
                $table->string('account_name')
                    ->after('order_type_id')
                    ->comment('帳戶名稱');
            });
        }

        if (Schema::hasColumn('bank_accounts', 'bank_account'))
        {
            Schema::table('bank_accounts', function (Blueprint $table) {
                $table->renameColumn('bank_account', 'account_no');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('bank_accounts', 'account_name'))
        {
            Schema::table('bank_accounts', function (Blueprint $table) {
                $table->dropColumn('account_name');
            });
        }

        if (Schema::hasColumn('bank_accounts', 'account_no'))
        {
            Schema::table('bank_accounts', function (Blueprint $table) {
                $table->renameColumn('account_no', 'bank_account');
            });
        }
    }
}
