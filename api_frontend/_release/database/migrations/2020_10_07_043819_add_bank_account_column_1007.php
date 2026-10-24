<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddBankAccountColumn1007 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('bank_accounts', 'min_amount') && !Schema::hasColumn('bank_accounts', 'max_amount'))
        {
            Schema::table('bank_accounts', function (Blueprint $table) {
                $table->decimal('min_amount', 18, 4)
                    ->after('account_no')
                    ->comment('帳戶名稱');
                $table->decimal('max_amount',18 , 4)
                    ->after('min_amount')
                    ->comment('帳戶名稱');
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
        if (Schema::hasColumn('bank_accounts', 'min_amount') && Schema::hasColumn('bank_accounts', 'max_amount'))
        {
            Schema::table('bank_accounts', function (Blueprint $table) {
                $table->dropColumn('min_amount');
                $table->dropColumn('max_amount');
            });
        }
    }
}
