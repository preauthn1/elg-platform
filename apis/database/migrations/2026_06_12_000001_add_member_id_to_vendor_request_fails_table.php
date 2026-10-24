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
        if (Schema::hasColumn('vendor_request_fails', 'member_id') === false) {
            SchemaMonitor::table('vendor_request_fails', function (Blueprint $table) {
                $table->integer('member_id')->default(0)->index()->after('general_agent_id')->comment('會員系統編號');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('vendor_request_fails', 'member_id') === true) {
            SchemaMonitor::table('vendor_request_fails', function (Blueprint $table) {
                $table->dropColumn('member_id');
            });
        }
    }
};
