<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddTransactionLogsColumn20201021 extends Migration
{
    protected $table_name = 'transaction_logs';
    protected $column_name = 'tax';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn($this->table_name, $this->column_name))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal($this->column_name, 18,4)
                    ->after('vendor_balance')
                    ->default(0)
                    ->comment('稅收');
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
        if (Schema::hasColumn($this->table_name, $this->column_name))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn($this->column_name);
            });
        }
    }
}
