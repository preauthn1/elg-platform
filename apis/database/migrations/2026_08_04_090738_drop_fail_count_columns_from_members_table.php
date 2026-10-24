<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 3967 階段二：移除 members 遺留的 5 個失敗計次欄位。
 *
 * 這批計數已於 3967 搬到 member_statistics，讀寫全部改走新表（LoginController /
 * ResetPasswordService / WithdrawalController / CheckValidationCodeService），
 * members 上的舊欄位自此不再被任何程式讀寫。留著會讓後續開發誤判仍在使用，故移除。
 *
 * 前置（皆已完成）：
 *  - 程式端已無讀寫（全 app/ 掃過，命中皆為 member_statistics 物件）
 *  - patch:backfill-member-hour-summaries 已移除讀這 5 欄的搬遷段落
 *  - 六白標正式庫實查：members 有值但 member_statistics 缺列者為 0
 *
 * members 為大表（正式最大 99 萬列），maxRows 放寬至 1 億：dropColumn 為 INSTANT DDL、
 * 不重建表，與同表新增欄位（2026_07_20_000003）同等風險。
 *
 * target/tickets#3967
 */
return new class extends Migration
{
    /** 移除的欄位（順序即原表欄位順序） */
    private const COLUMNS = [
        'total_login_fail_count',
        'login_fail_count',
        'forget_pwd_count',
        'payment_pwd_fail_count',
        'validation_code_fail_count',
    ];

    public function up(): void
    {
        SchemaMonitor::table('members', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                if (SchemaMonitor::hasColumn('members', $column)) {
                    $table->dropColumn($column);
                }
            }
        }, maxRows: 100_000_000);
    }

    /**
     * 還原為搬遷前的 schema（型別／預設值／nullable 依原始 migration）。
     * 只還原欄位、不還原資料——歷史計數已在 member_statistics。
     */
    public function down(): void
    {
        SchemaMonitor::table('members', function (Blueprint $table) {
            // 原 create_members_table：nullable + default 0
            if (!SchemaMonitor::hasColumn('members', 'total_login_fail_count')) {
                $table->integer('total_login_fail_count')->nullable()->default(0)->comment('總登入錯誤次數');
            }
            if (!SchemaMonitor::hasColumn('members', 'login_fail_count')) {
                $table->integer('login_fail_count')->nullable()->default(0)->comment('登入錯誤次數');
            }
            if (!SchemaMonitor::hasColumn('members', 'forget_pwd_count')) {
                $table->integer('forget_pwd_count')->nullable()->default(0)->comment('忘記密碼次數');
            }
            // 原 add_payment_pwd_fail_count_to_members_table：not null + default 0
            if (!SchemaMonitor::hasColumn('members', 'payment_pwd_fail_count')) {
                $table->integer('payment_pwd_fail_count')->default(0)->after('forget_pwd_count')->comment('支付密碼錯誤次數');
            }
            if (!SchemaMonitor::hasColumn('members', 'validation_code_fail_count')) {
                $table->integer('validation_code_fail_count')->default(0)->after('payment_pwd_fail_count')->comment('驗證碼錯誤次數');
            }
        }, maxRows: 100_000_000);
    }
};
