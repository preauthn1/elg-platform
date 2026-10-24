<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        SchemaMonitor::table('n918_accounts', function (Blueprint $table) {
            $table->string('password')->after('player_id')->comment('密碼');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        SchemaMonitor::table('n918_accounts', function (Blueprint $table) {
            $table->dropColumn('password');
        });
    }
};
