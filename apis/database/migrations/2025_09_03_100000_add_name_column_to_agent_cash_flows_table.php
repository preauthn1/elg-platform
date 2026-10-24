<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddNameColumnToAgentCashFlowsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // 檢查是否已經有這個欄位
        if (!Schema::hasColumn('agent_cash_flows', 'name')) {
            Schema::table('agent_cash_flows', function (Blueprint $table) {
                $table->string('name')->default('')->comment('名稱')->after('rate');
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
        Schema::table('agent_cash_flows', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
}
