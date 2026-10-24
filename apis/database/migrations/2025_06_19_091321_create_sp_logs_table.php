<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::create('sp_logs', function (Blueprint $table) {
            $table->comment('SP異動紀錄');
            $table->integer('id', true);
            $table->string('routine_schema', 64)->comment('儲存程序的資料庫名稱');
            $table->string('routine_name', 64)->comment('儲存程序的名稱');
            $table->longText('routine_definition')->comment('儲存程序的完整定義');
            $table->integer('version_number')->comment('儲存程序的版本號（遞增）');
            $table->timestamp('created_at')->useCurrent()->comment('紀錄新增時間');

            $table->unique(['routine_schema', 'routine_name', 'version_number'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('sp_logs');
    }
};
