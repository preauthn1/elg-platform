<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddTransferLimitLogsColumn0201 extends Migration
{
    protected $table_name = 'transfer_limit_logs';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn($this->table_name, 'memo')) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->string('memo')
                    ->after('after_limit')
                    ->nullable()
                    ->comment('備註');
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
        if (Schema::hasColumn($this->table_name, 'memo')) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn('memo');
            });
        }
    }
}
