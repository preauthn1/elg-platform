<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('wudisw_accounts', 'status') === false) {
            SchemaMonitor::table('wudisw_accounts', function (Blueprint $table) {
                $table->boolean('status')->default(1)->comment('狀態 0:關閉,1:正常')->after('member_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('wudisw_accounts', 'status') === true) {
            SchemaMonitor::table('wudisw_accounts', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
    }
};
