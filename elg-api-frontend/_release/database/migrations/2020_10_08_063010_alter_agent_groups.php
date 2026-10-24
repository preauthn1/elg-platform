<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterAgentGroups extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('agent_groups', function($table) {
            $table->integer('organization_id')
                ->after('agent_group_type')
                ->default(1)
                ->comment('所屬組織');
        });

        Schema::table('agent_groups', function($table) {
            $table->integer('agent_group_type')
                ->comment('1:基本個性化設置組別、2:財務組別  3:簡訊設置組別 4.聊天chatGroup相關')
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}