<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 修改已存在的 updated_at 欄位
        SchemaMonitor::table('realname_validation_requests', function (Blueprint $table) {
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'))
                ->change();
        });

        if (Schema::hasColumn('realname_validation_requests', 'bank_id') === false) {
            SchemaMonitor::table('realname_validation_requests', function (Blueprint $table) {
                $table->integer('bank_id')->nullable()->after('realname')->comment('銀行id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('realname_validation_requests', 'bank_id') === true) {
            SchemaMonitor::table('realname_validation_requests', function (Blueprint $table) {
                $table->dropColumn('bank_id');
            });
        }
    }
};
