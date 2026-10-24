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
        SchemaMonitor::table('n918_bet_records', function (Blueprint $table) {
            $table->index('username');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        SchemaMonitor::table('n918_bet_records', function (Blueprint $table) {
            $table->dropIndex('n918_bet_records_username_index');
        });
    }
};
