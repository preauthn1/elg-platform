<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSortToBrandAgentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // 檢查是否已經有這個欄位
        if (!Schema::hasColumn('brand_agents', 'sort')) {
            Schema::table('brand_agents', function (Blueprint $table) {
                $table->integer('sort')->default(0)->after('agent_id');
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
        Schema::table('brand_agents', function (Blueprint $table) {
            $table->dropColumn('sort');
        });
    }
}
