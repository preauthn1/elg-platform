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
        if (Schema::hasColumn('agents', 'realname_validate') === false) {
            SchemaMonitor::table('agents', function (Blueprint $table) {
                $table->tinyInteger('realname_validate')->default(0)->after('last_reset_password')->comment('代理實名認證, 0:未認證, 1:已認證, 2:認證中');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('agents', 'realname_validate') === true) {
            SchemaMonitor::table('agents', function (Blueprint $table) {
                $table->dropColumn('realname_validate');
            });
        }
    }
};
