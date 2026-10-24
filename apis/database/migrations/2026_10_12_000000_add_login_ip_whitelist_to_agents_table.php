<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 營運商編輯新增「登入 IP 白名單」（target/tickets#4498）：只限制被設定的那個帳號本身，
// 子帳號與下線不繼承；NULL 或空字串＝不限制。存正規化後的逗號字串（去空白、去重），
// 格式驗證與登入比對在 API_Agent（agent/edit、Auth/LoginController）。
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('agents', function (Blueprint $table): void {
            $table->text('login_ip_whitelist')
                ->nullable()
                ->after('has_2fa')
                ->comment('登入 IP 白名單，逗號分隔的 IPv4/IPv6；NULL 或空＝不限制（target/tickets#4498）');
        });

        // SchemaMonitor 在 production 遇列數超過上限會靜默跳過且 migration 仍記為已執行，這裡確認欄位真的在
        if (!Schema::hasColumn('agents', 'login_ip_whitelist')) {
            throw new RuntimeException('agents.login_ip_whitelist 未建立：SchemaMonitor 可能因列數上限跳過，請改以手寫 DDL 補上');
        }
    }

    public function down(): void
    {
        SchemaMonitor::table('agents', function (Blueprint $table): void {
            $table->dropColumn('login_ip_whitelist');
        });

        if (Schema::hasColumn('agents', 'login_ip_whitelist')) {
            throw new RuntimeException('agents.login_ip_whitelist 未移除：SchemaMonitor 可能因列數上限跳過');
        }
    }
};
