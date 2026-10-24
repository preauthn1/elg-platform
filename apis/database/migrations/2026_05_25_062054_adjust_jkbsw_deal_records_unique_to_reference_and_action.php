<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Joker Baccarat SW：把 deal_records 的冪等鍵從單一 `reference_no` 改為複合
 * `(reference_no, action)`。
 *
 * 三方實測會以同一個 `transactionNo` 同時下發 Bet (action=2) + Refund (action=4)，
 * 雖然違反官方 seamless-wallet-api.md line 183 寫的「不同 action 同 transactionNo
 * 應視為流程錯誤」，但三方實作如此；為避免 Refund 因撞 UNIQUE 被吃成 idempotent
 * success 而無法沖銷 BR，這裡放寬 UNIQUE 邊界，靠 (reference_no, action) 仍保有
 * 同 action 重送的冪等性。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('jkbsw_deal_records', function (Blueprint $table) {
            $table->dropUnique('jkbsw_deal_records_reference_no_unique');
            $table->unique(['reference_no', 'action'], 'jkbsw_deal_records_reference_no_action_unique');
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('jkbsw_deal_records', function (Blueprint $table) {
            $table->dropUnique('jkbsw_deal_records_reference_no_action_unique');
            $table->unique('reference_no', 'jkbsw_deal_records_reference_no_unique');
        });
    }
};
