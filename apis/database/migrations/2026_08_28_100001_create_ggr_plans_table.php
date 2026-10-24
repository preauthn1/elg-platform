<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 推廣代理 GGR 方案主表 工單4121
 *
 * 一筆＝一個 GGR 分潤方案：結算週期（日/週/月）、費率、防套利開關、
 * 成員下線設置（綁 4123 遊戲商設定＋4124 條件設定各一，即公式的
 * GGR 計算基底與「配置給代理的GGR%」來源鏈）。特殊用戶 override
 * 在 ggr_plan_special_agents。批次三的結算引擎/派發紀錄以本表 id
 * 為引用契約，不做硬刪（停用靠 status）。
 *
 * 帳戶驗證限制只做系統能驗的 5 項（銀行/號碼/信箱/IP/姓名）；
 * 指紋（無 device fingerprint 機制）與密碼（bcrypt 帶 salt 無法比對唯一）
 * 不做——2026-08-28 使用者定案。
 *
 * 僅總代使用，general_agent_id 為可見性軸（同批次一）。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('ggr_plans', function (Blueprint $table) {
            $table->comment('推廣代理GGR方案主表 工單4121');
            $table->increments('id')->comment('PK流水號(批次三結算/4125引用契約,不硬刪)');
            $table->unsignedInteger('general_agent_id')->comment('所屬總代agents.id(各總代方案不互見,可見性軸)');
            $table->string('name', 100)->comment('GGR標題');
            $table->tinyInteger('settlement_cycle')->comment('結算週期 1:每日 2:每週 3:每月');
            $table->integer('agent_group_id')->comment('代理群組agent_groups.id(個性化設置組別type=1)');
            $table->date('start_date')->comment('開始日期(GMT+8業務日)');
            $table->tinyInteger('carry_negative')->default(0)->comment('发扬 0:否 1:負數GGR用原始數據結轉下期');
            $table->tinyInteger('auto_approve')->default(0)->comment('自動審核通過 0:否 1:是');
            $table->tinyInteger('auto_payout')->default(0)->comment('自動發放 0:否 1:是');
            $table->integer('valid_days')->nullable()->comment('有效天數(發放後N天未領取過期作廢,NULL=不過期,過期邏輯批次三)');
            $table->decimal('admin_fee_rate', 8, 2)->default(0)->comment('管理費%(公式:ΣGGR×管理費%,對原始總GGR抽)');
            $table->decimal('handling_fee', 18, 2)->default(0)->comment('手續費(固定金額)');
            $table->decimal('ggr_rate', 8, 2)->default(0)->comment('GGR百分比%');
            $table->decimal('bonus_rate', 8, 2)->default(0)->comment('獎金百分比%(公式的(1-獎金%)項)');
            $table->tinyInteger('kyc_unique_bank_account')->default(0)->comment('銀行帳號唯一限制 0:關 1:開(member_bank_accounts)');
            $table->tinyInteger('kyc_unique_contact_no')->default(0)->comment('手機號碼唯一限制 0:關 1:開(members.mobile)');
            $table->tinyInteger('kyc_unique_email')->default(0)->comment('信箱唯一限制 0:關 1:開(members.email)');
            $table->tinyInteger('kyc_unique_ip')->default(0)->comment('IP唯一限制 0:關 1:開(members.register_ip)');
            $table->tinyInteger('kyc_unique_name')->default(0)->comment('姓名唯一限制 0:關 1:開(members.first/last_name)');
            $table->integer('kyc_violate_count')->default(0)->comment('違規次數');
            $table->tinyInteger('verify_bank')->default(0)->comment('領取前驗證銀行資訊 0:關 1:開');
            $table->tinyInteger('verify_email')->default(0)->comment('領取前驗證信箱 0:關 1:開(email_validate)');
            $table->tinyInteger('verify_mobile')->default(0)->comment('領取前驗證手機 0:關 1:開(mobile_validate)');
            $table->tinyInteger('verify_profile')->default(0)->comment('領取前驗證完整資料 0:關 1:開(is_validate)');
            $table->integer('verify_count')->default(0)->comment('驗證次數限制');
            $table->unsignedInteger('downline_vendor_setting_id')->comment('成員下線設置:ggr_vendor_settings.id(GGR計算基底)');
            $table->unsignedInteger('downline_condition_id')->comment('成員下線設置:ggr_conditions.id(命中階→應得%)');
            $table->tinyInteger('status')->default(1)->comment('狀態 0:停用 1:啟用');
            $table->integer('created_by')->default(0)->comment('建立人員ID');
            $table->integer('admin')->default(0)->comment('更新者ID');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->index('general_agent_id', 'idx_ggr_plan_general_agent');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('ggr_plans');
    }
};
