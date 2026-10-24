<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * admin_operation_logs 補「人工要求中斷」旗標
 *
 * 後台的「中斷」原本只把紀錄標成失敗、停不掉正在跑的進程（見 AbstractOperationPage::cancelWarning），
 * 所以按鈕只在心跳斷掉時才給按。本欄位讓長時指令自己輪詢、跑到安全點主動收尾：
 * 按鈕寫時間戳、指令讀到就 break 並印出續跑起點，紀錄仍由 RunAdminOperationJob 正常收尾。
 *
 * 不復用 status 當訊號：cancel 若直接把 status 寫成失敗，指令收尾時 job 會再覆寫一次狀態與輸出，
 * 「人工中斷」與「執行失敗」也就分不出來。旗標與狀態分開才留得住兩邊語意。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('admin_operation_logs', function (Blueprint $table) {
            $table->dateTime('cancel_requested_at')->nullable()->after('finished_at')
                ->comment('人工要求中斷的時間（null = 未要求）；支援的長時指令輪詢此欄後自行收尾');
        });

        // SchemaMonitor 在表列數超限等情況會靜默跳過、migration 仍記為已執行，補生效斷言
        if (!Schema::hasColumn('admin_operation_logs', 'cancel_requested_at')) {
            throw new RuntimeException('admin_operation_logs.cancel_requested_at 未建立，請確認 SchemaMonitor 是否跳過');
        }
    }

    public function down(): void
    {
        SchemaMonitor::table('admin_operation_logs', function (Blueprint $table) {
            $table->dropColumn('cancel_requested_at');
        });
    }
};
