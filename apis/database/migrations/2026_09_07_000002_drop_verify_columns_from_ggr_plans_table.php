<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 移除 GGR 方案的「領取前驗證條件」整組欄位 工單4121
 *
 * 2026-09-07 使用者定案：4121 不做領取前驗證，該區塊（驗證銀行資訊／信箱／
 * 手機／完整資料＋驗證次數限制）連同 DB 欄位、表單畫面與結算前的會員篩選
 * 一併移除。上半部「帳戶驗證限制」（5 項唯一限制＋違規次數）保留不變。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('ggr_plans', function (Blueprint $table) {
            $table->dropColumn(['verify_bank', 'verify_email', 'verify_mobile', 'verify_profile', 'verify_count']);
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('ggr_plans', function (Blueprint $table) {
            $table->tinyInteger('verify_bank')->default(0)->after('kyc_violate_count')->comment('領取前驗證銀行資訊 0:否 1:是');
            $table->tinyInteger('verify_email')->default(0)->after('verify_bank')->comment('領取前驗證信箱 0:否 1:是');
            $table->tinyInteger('verify_mobile')->default(0)->after('verify_email')->comment('領取前驗證手機 0:否 1:是');
            $table->tinyInteger('verify_profile')->default(0)->after('verify_mobile')->comment('領取前驗證完整資料 0:否 1:是');
            $table->integer('verify_count')->default(0)->after('verify_profile')->comment('驗證次數限制(0=不設限)');
        });
    }
};
