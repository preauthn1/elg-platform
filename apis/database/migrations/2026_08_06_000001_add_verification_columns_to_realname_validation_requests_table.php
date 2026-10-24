<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 實名驗證申請補證件欄位（證件號碼、出生日期、證件正反面照片）。
 * 銀行卡改為可依設定關閉，銀行名稱與帳號一併放寬為 nullable（對齊後加的 bank_id）。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('realname_validation_requests', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('realname_validation_requests', 'id_number')) {
                $table->string('id_number', 100)->nullable()->after('bank_account')->comment('證件號碼');
            }
            if (!SchemaMonitor::hasColumn('realname_validation_requests', 'birthday')) {
                $table->date('birthday')->nullable()->after('id_number')->comment('出生日期');
            }
            if (!SchemaMonitor::hasColumn('realname_validation_requests', 'id_front_image')) {
                $table->text('id_front_image')->nullable()->after('birthday')->comment('證件正面照片');
            }
            if (!SchemaMonitor::hasColumn('realname_validation_requests', 'id_back_image')) {
                $table->text('id_back_image')->nullable()->after('id_front_image')->comment('證件反面照片');
            }

            // change() 會重寫欄位完整定義，comment 必須一起寫回，否則原本的註解會消失
            $table->string('bank_name', 100)->nullable()->comment('銀行名稱')->change();
            $table->string('bank_account', 100)->nullable()->comment('銀行帳號')->change();
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('realname_validation_requests', function (Blueprint $table) {
            foreach (['id_number', 'birthday', 'id_front_image', 'id_back_image'] as $column) {
                if (SchemaMonitor::hasColumn('realname_validation_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
