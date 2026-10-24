<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    private const TABLE = 'member_login_ip_logs';

    /**
     * 新增統一 IP 欄位（完整字串，同時支援 IPv4 / IPv6）。
     *
     * 原四段 ip_1~ip_4 僅能存 IPv4，IPv6 登入現況被 LoginService 直接丟棄。
     * 新增 varchar(45) 欄位存完整 IP 字串（IPv4 最長 15、IPv6 最長 45）作為單一來源：
     *  - IPv4：四段照舊寫，另存字串於此欄
     *  - IPv6：四段填 0（滿足 NOT NULL），完整字串存此欄
     * 舊資料由 member:backfill-ip-address 指令分批補回。
     *
     * 千萬級大表：加欄位 + 索引為 MySQL 8 online DDL（INPLACE），需離峰執行；
     * maxRows 放大涵蓋各白標筆數。target/tickets#3963
     */
    public function up(): void
    {
        SchemaMonitor::table(self::TABLE, function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn(self::TABLE, 'ip_address')) {
                $table->string('ip_address', 45)->nullable()->index()->after('ip')->comment('完整登入IP(IPv4/IPv6通用) 工單3963');
            }
        }, maxRows: 100_000_000);
    }

    public function down(): void
    {
        SchemaMonitor::table(self::TABLE, function (Blueprint $table) {
            if (SchemaMonitor::hasColumn(self::TABLE, 'ip_address')) {
                $table->dropIndex(['ip_address']);
                $table->dropColumn('ip_address');
            }
        }, maxRows: 100_000_000);
    }
};
