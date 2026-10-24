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
     * 将 banks 表的 name 字段长度从默认值增加到 100
     */
    public function up(): void
    {
        SchemaMonitor::table('banks', function (Blueprint $table) {
            // 修改 name 字段长度为 100
            $table->string('name', 100)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * 回滚时将 name 字段长度恢复为原始长度 50
     */
    public function down(): void
    {
        SchemaMonitor::table('banks', function (Blueprint $table) {
            // 回滚到原始长度 50
            $table->string('name', 50)->change();
        });
    }
};
