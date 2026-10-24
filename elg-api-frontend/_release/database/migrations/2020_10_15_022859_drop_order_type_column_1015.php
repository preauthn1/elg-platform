<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class DropOrderTypeColumn1015 extends Migration
{
    protected $table_name = 'order_types';
    protected $code = 'agent_group_id';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn($this->table_name, $this->code)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn([$this->code]);
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
        if (!Schema::hasColumn($this->table_name, $this->code)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->integer($this->code)
                    ->after('cash_flow_id')
                    ->comment('代理群組編號');
            });
        }
    }
}
