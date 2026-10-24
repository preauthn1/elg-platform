<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRealNameToAgentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // 檢查是否已經有這個欄位
        if (!Schema::hasColumn('agents', 'real_name')) {
            Schema::table('agents', function (Blueprint $table) {
                $table->string('real_name')->default('')->after('nickname');
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
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('real_name');
        });
    }
}
