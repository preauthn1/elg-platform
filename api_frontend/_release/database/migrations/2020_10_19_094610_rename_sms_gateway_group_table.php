<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class RenameSmsGatewayGroupTable extends Migration
{
    protected $from = 'sms_gateway_groups';
    protected $to = 'sms_gateway_group_details';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable($this->from)){
            Schema::rename($this->from, $this->to);

            if (Schema::hasColumn($this->to, 'organization_id'))
            {
                Schema::table($this->to, function (Blueprint $table) {
                    $table->dropColumn('organization_id');
                });
            }
            if (Schema::hasColumn($this->to, 'name'))
            {
                Schema::table($this->to, function (Blueprint $table) {
                    $table->dropColumn('name');
                });
            }
            if (Schema::hasColumn($this->to, 'memo'))
            {
                Schema::table($this->to, function (Blueprint $table) {
                    $table->dropColumn('memo');
                });
            }
            if (Schema::hasColumn($this->to, 'status'))
            {
                Schema::table($this->to, function (Blueprint $table) {
                    $table->dropColumn('status');
                });
            }

            if (Schema::hasTable($this->to)){
                Schema::table($this->to, function($table) {
                    $table->integer('agent_group_id')
                        ->after('id')
                        ->default(0)
                        ->comment('agent_group.id');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable($this->to)){
            Schema::rename($this->to, $this->from);
        }
    }
}
