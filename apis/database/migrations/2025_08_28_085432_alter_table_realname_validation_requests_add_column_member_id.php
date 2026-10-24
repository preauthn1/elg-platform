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
        // 請使用 SchemaMonitor 操作避免線上環境炸掉
        if (Schema::hasColumn('realname_validation_requests', 'member_id') === false) {
            SchemaMonitor::table('realname_validation_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('member_id')->default(0)->after('id')->index();
                $table->string('username')->nullable()->default(null)->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 請使用 SchemaMonitor 操作避免線上環境炸掉
        if (Schema::hasColumn('realname_validation_requests', 'member_id') === true) {
            SchemaMonitor::table('realname_validation_requests', function (Blueprint $table) {
                $table->dropColumn('member_id');
            });
        }
    }
};
