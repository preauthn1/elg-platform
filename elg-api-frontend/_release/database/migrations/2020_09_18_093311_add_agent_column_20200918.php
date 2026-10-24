<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddAgentColumn20200918 extends Migration
{
    protected $table_name = 'agents';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn($this->table_name, 'sms_gateway_group_id'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->tinyInteger('sms_gateway_group_id')
                    ->after('cash_flow_group_id')
                    ->default(0)
                    ->comment('簡訊群組ID');
            });
        }
        if (!Schema::hasColumn($this->table_name, 'chat_group_id'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->tinyInteger('chat_group_id')
                    ->after('sms_gateway_group_id')
                    ->default(0)
                    ->comment('聊天群組ID');
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

        if (Schema::hasColumn($this->table_name, 'sms_gateway_group_id'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn('sms_gateway_group_id');
            });
        }
        if (Schema::hasColumn($this->table_name, 'chat_group_id'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn('chat_group_id');
            });
        }
    }
}
