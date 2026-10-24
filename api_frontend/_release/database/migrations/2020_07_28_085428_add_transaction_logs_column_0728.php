<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddTransactionLogsColumn0728 extends Migration
{
    protected $table_name = 'transaction_logs';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn($this->table_name, 'before_balance'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal('before_balance', 10, 2)
                    ->after('rate')
                    ->default(0)
                    ->comment('变更前数点');
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
        if (Schema::hasColumn($this->table_name, 'before_balance'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn('before_balance');
            });
        }
    }
}
